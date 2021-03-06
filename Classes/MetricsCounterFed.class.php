<?php

require_once __DIR__ . '/CurlWrapper.class.php';
require_once __DIR__ . '/MetricsTaxonomy.class.php';
require_once __DIR__ . '/MetricsTaxonomiesTree.class.php';

use \Aws\Common\Aws;

/**
 * Class MetricsCounterFed
 */
class MetricsCounterFed
{
  /**
   * cURL handler
   * @var CurlWrapper
   */
  private $curl;
  /**
   * @var mixed|string
   */
  private $ckanUrl = '';
  /**
   * @var mixed|string
   */
  private $ckanApiUrl = '';
  /**
   * @var int
   */
  private $stats = 0;
  /**
   * @var int
   */
  private $statsByMonth = 0;
  /**
   * @var array
   */
  private $results = array();
  /**
   * @var WP_DB
   */
  private $wpdb;
  /**
   * @var array
   */
  private $counts = array();

  /**
   *
   */
  function __construct()
  {
    $this->ckanUrl = get_option('ckan_access_pt') ?: '//catalog.data.gov/';
    $this->ckanUrl = str_replace(array('http:', 'https:'), array('', ''), $this->ckanUrl);

    $this->ckanApiUrl = get_option('ckan_api_endpoint') ?: '//catalog.data.gov/';
    $this->ckanApiUrl = str_replace(array('http:', 'https:'), array('', ''), $this->ckanApiUrl);

    global $wpdb;
    $this->wpdb = $wpdb;

    $this->curl = new CurlWrapper();
  }

  /**
   *
   */
  public function updateMetrics()
  {
    $taxonomies = $this->ckan_metric_get_taxonomies();

    //    Create taxonomy families, with parent taxonomy and sub-taxonomies (children)
    $TaxonomiesTree = $this->ckan_metric_convert_structure($taxonomies);

    $FederalOrganizationTree = $TaxonomiesTree->getVocabularyTree('Federal Organization');

    /** @var MetricsTaxonomy $RootOrganization */
    foreach ($FederalOrganizationTree as $RootOrganization) {
      //        skip broken structures
      if (!$RootOrganization->getTerm()) {
        /**
         * Ugly TEMPORARY hack for missing
         * Executive Office of the President [eop-gov]
         */
        try {
          $children = $RootOrganization->getTerms();
          $firstChildTerm = trim($children[0], '(")');
          list (, $fed, $gov) = explode('-', $firstChildTerm);
          if (!$fed || !$gov) {
            continue;
          }
          $RootOrganization->setTerm("$fed-$gov");
          //                    echo "uglyfix: $fed-$gov<br />" . PHP_EOL;
        } catch (Exception $ex) {
          //                    didn't help. Skip
          continue;
        }
      }

      $solr_terms = join('+OR+', $RootOrganization->getTerms());
      $solr_query = "organization:({$solr_terms})";

      /**
       * Collect statistics and create data for ROOT organization
       */

      $parent_nid = $this->create_metric_content(
        $RootOrganization->getIsCfo(),
        $RootOrganization->getTitle(),
        $RootOrganization->getTerm(),
        $solr_query,
        0,
        1,
        '',
        0
      );

      /**
       * Check if there are some Department/Agency level datasets
       * without publisher!
       */
      $this->create_metric_content_department_level_without_publisher(
        $RootOrganization,
        $parent_nid
      );

      /**
       * Get publishers by organization
       */
      $this->create_metric_content_by_publishers(
        $RootOrganization,
        $parent_nid
      );

      /**
       * Collect statistics and create data for SUB organizations of current $RootOrganization
       */
      $SubOrganizations = $RootOrganization->getChildren();
      if ($SubOrganizations) {
        /** @var MetricsTaxonomy $Organization */
        foreach ($SubOrganizations as $Organization) {
          $this->create_metric_content(
            $Organization->getIsCfo(),
            $Organization->getTitle(),
            $Organization->getTerm(),
            'organization:' . urlencode($Organization->getTerm()),
            $parent_nid,
            0,
            $RootOrganization->getTitle(),
            1,
            1
          );
        }
      }

    }

    $this->write_metrics_csv_and_xls();

    echo '<hr />get count: ' . $this->stats . ' times<br />';
    echo 'get count by month: ' . $this->statsByMonth . ' times<br />';
  }

  /**
   * @return mixed
   */
  private function ckan_metric_get_taxonomies()
  {

    $response = file_get_contents(WP_CONTENT_DIR . '/themes/roots-nextdatagov/assets/Json/fed_agency.json');
    $body = json_decode($response, true);
    $taxonomies = $body['taxonomies'];
    return $taxonomies;
  }

  /**
   * @param $taxonomies
   *
   * @return MetricsTaxonomiesTree
   */
  private function ckan_metric_convert_structure($taxonomies)
  {
    $Taxonomies = new MetricsTaxonomiesTree();

    foreach ($taxonomies as $taxonomy) {
      $taxonomy = $taxonomy['taxonomy'];

      //        ignore bad ones
      if (strlen($taxonomy['unique id']) == 0) {
        continue;
      }

      //        Empty Federal Agency = illegal
      if (!$taxonomy['Federal Agency']) {
        continue;
      }

      //        ignore 3rd level ones
      if ($taxonomy['unique id'] != $taxonomy['term']) {
        // continue;
      }

      //        Make sure we got $return[$sector], ex. $return['Federal Organization']
      if (!isset($return[$taxonomy['vocabulary']])) {
        $return[$taxonomy['vocabulary']] = array();
      }

      $RootAgency = $Taxonomies->getRootAgency($taxonomy['Federal Agency'], $taxonomy['vocabulary']);

      if (!($RootAgency)) {
        //            create root agency if doesn't exist yet
        $RootAgency = new MetricsTaxonomy($taxonomy['Federal Agency']);
        $RootAgency->setIsRoot(true);
      }

      //        This is for third level agency
      if ($taxonomy['unique id'] != $taxonomy['term']) {
        $Agency = new MetricsTaxonomy($taxonomy['term']);
        $Agency->setTerm($taxonomy['unique id']);
        $Agency->setIsCfo($taxonomy['is_cfo']);
        $RootAgency->addChild($Agency);
      } else if (strlen($taxonomy['Sub Agency']) != 0) {
        //        This is sub-agency
        $Agency = new MetricsTaxonomy($taxonomy['Sub Agency']);
        $Agency->setTerm($taxonomy['unique id']);
        $Agency->setIsCfo($taxonomy['is_cfo']);
        $RootAgency->addChild($Agency);
      } else {
        //        ELSE this is ROOT agency
        $RootAgency->setTerm($taxonomy['unique id']);
        $RootAgency->setIsCfo($taxonomy['is_cfo']);
      }

      //        updating the tree
      $Taxonomies->updateRootAgency($RootAgency, $taxonomy['vocabulary']);
    }


    return $Taxonomies;
  }

  /**
   * @param        $cfo
   * @param        $title
   * @param        $ckan_id
   * @param        $organizations
   * @param int $parent_node
   * @param int $agency_level
   * @param string $parent_name
   * @param int $sub_agency
   * @param int $export
   *
   * @return mixed
   */
  private function create_metric_content(
    $cfo,
    $title,
    $ckan_id,
    $organizations,
    $parent_node = 0,
    $agency_level = 0,
    $parent_name = '',
    $sub_agency = 0,
    $export = 0
  )
  {

    if (strlen($ckan_id) != 0) {
      $url = $this->ckanApiUrl . "api/3/action/package_search?fq=($organizations)+AND+dataset_type:dataset&rows=1&sort=metadata_modified+desc";

      $this->stats++;
      $response = $this->curl->get($url);
      $body = json_decode($response, true);
      $count = $body['result']['count'];

      if ($count) {
        $last_entry = $body['result']['results'][0]['metadata_modified'];
        //        2013-12-12T07:39:40.341322

        $last_entry = substr($last_entry, 0, 10);
        //        2013-12-12

      } else {
        $last_entry = '1970-01-01';
      }
    } else {
      $count = 0;
    }

    $metric_sync_timestamp = time();

    if (!$sub_agency && $cfo == 'Y') {
      //get list of last 12 months
      $month = date('m');

      $startDate = mktime(0, 0, 0, $month - 11, 1, date('Y'));
      $endDate = mktime(0, 0, 0, $month, date('t'), date('Y'));

      $tmp = date('mY', $endDate);

      $oneYearAgo = date('Y-m-d', $startDate);

      while (true) {
        $months[] = array(
          'month' => date('m', $startDate),
          'year' => date('Y', $startDate)
        );

        if ($tmp == date('mY', $startDate)) {
          break;
        }

        $startDate = mktime(0, 0, 0, date('m', $startDate) + 1, 15, date('Y', $startDate));
      }

      $dataset_count = array();
      $dataset_range = array();

      $i = 1;

      /**
       * Get metrics by current $organizations for each of latest 12 months
       */
      foreach ($months as $date_arr) {
        $startDt = date('Y-m-d', mktime(0, 0, 0, $date_arr['month'], 1, $date_arr['year']));
        $endDt = date('Y-m-t', mktime(0, 0, 0, $date_arr['month'], 1, $date_arr['year']));

        $range = "[" . $startDt . "T00:00:00Z%20TO%20" . $endDt . "T23:59:59Z]";

        $url = $this->ckanApiUrl . "api/3/action/package_search?fq=({$organizations})+AND+dataset_type:dataset+AND+metadata_modified:{$range}&rows=0";
        $this->statsByMonth++;
        $response = $this->curl->get($url);
        $body = json_decode($response, true);

        $dataset_count[$i] = $body['result']['count'];
        $dataset_range[$i] = $range;
        $i++;
      }

      /**
       * Get metrics by current $organizations for latest 12 months TOTAL
       */

      $range = "[" . $oneYearAgo . "T00:00:00Z%20TO%20NOW]";

      $url = $this->ckanApiUrl . "api/3/action/package_search?fq=({$organizations})+AND+dataset_type:dataset+AND+metadata_modified:$range&rows=0";

      $this->statsByMonth++;
      $response = $this->curl->get($url);
      $body = json_decode($response, true);

      $lastYearCount = $body['result']['count'];
      $lastYearRange = $range;
    }

    //        create a new agency in DB, if not found yet
    $my_post = array(
      'post_title' => $title,
      'post_status' => 'publish',
      'post_type' => 'metric_new'
    );

    $content_id = wp_insert_post($my_post);

    list($Y, $m, $d) = explode('-', $last_entry);
    $last_entry = "$m/$d/$Y";

    $this->update_post_meta($content_id, 'metric_count', $count);

    if (!$sub_agency && $cfo == 'Y') {
      for ($i = 1; $i < 13; $i++) {
        $this->update_post_meta($content_id, 'month_' . $i . '_dataset_count', $dataset_count[$i]);
      }

      $this->update_post_meta($content_id, 'last_year_dataset_count', $lastYearCount);

      for ($i = 1; $i < 13; $i++) {
        $this->update_post_meta(
          $content_id,
          'month_' . $i . '_dataset_url',
          $this->ckanUrl . 'dataset?q=(' . $organizations . ')+AND+dataset_type:dataset+AND+metadata_modified:' . $dataset_range[$i]
        );
      }

      $this->update_post_meta(
        $content_id,
        'last_year_dataset_url',
        $this->ckanUrl . 'dataset?q=(' . $organizations . ')+AND+dataset_type:dataset+AND+metadata_modified:' . $lastYearRange
      );

    }

    if ($cfo == 'Y') {
      $this->update_post_meta($content_id, 'metric_sector', 'Federal Government');
      $organization_type = 'Federal Government';
    } else {
      $this->update_post_meta($content_id, 'metric_sector', 'Other Federal');
      $organization_type = 'Other Federal';
    }

    $this->update_post_meta($content_id, 'ckan_unique_id', $ckan_id);
    $this->update_post_meta($content_id, 'metric_last_entry', $last_entry);
    $this->update_post_meta($content_id, 'metric_sync_timestamp', $metric_sync_timestamp);

    $this->update_post_meta(
      $content_id,
      'metric_url',
      $this->ckanUrl . 'dataset?q=' . $organizations
    );

    if (!$sub_agency) {
      $this->update_post_meta($content_id, 'is_root_organization', 1);
      $this->counts[trim($title)] = $count;
    } else {
      $this->update_post_meta($content_id, 'is_sub_organization', 1);
    }

    if ($parent_node != 0) {
      $this->update_post_meta($content_id, 'parent_organization', $parent_node);
    }

    if ($agency_level != 0) {
      $this->update_post_meta($content_id, 'parent_agency', 1);
    }

    $flag = false;
    if ($count > 0) {
      if ($export != 0) {
        $this->results[] = array($parent_name, $title, $organization_type, $count, $last_entry);
      }

      if ($parent_node == 0 && $flag == false) {
        $parent_name = $title;
        $title = '';

        $this->results[] = array($parent_name, $title, $organization_type, $count, $last_entry);
      }
    }

    return $content_id;
  }

  /**
   * Temporary to remove all duplicate meta
   * Removes ONLY with manual launch
   *
   * @param $post_id
   * @param $meta_key
   * @param $meta_value
   */
  private function update_post_meta($post_id, $meta_key, $meta_value)
  {
    //        if (defined('DELETE_DUPLICATE_META') && DELETE_DUPLICATE_META) {
    //            delete_post_meta($post_id, $meta_key);
    //        }
    update_post_meta($post_id, $meta_key, $meta_value);
  }

  /**
   * @param MetricsTaxonomy $RootOrganization
   * @param                 $parent_nid
   */
  private function create_metric_content_department_level_without_publisher($RootOrganization, $parent_nid)
  {
    $publisherTitle = '    Department/Agency level/No publisher';

    //        https://catalog.data.gov/api/3/action/package_search?q=organization:(gsa-gov)+AND+type:dataset+AND+-extras_publisher:*&sort=metadata_modified+desc&rows=1
    $ckan_organization = 'organization:' . urlencode(
        $RootOrganization->getTerm()
      ) . '+AND+type:dataset+AND+-extras_publisher:*';
    $url = $this->ckanApiUrl . "api/3/action/package_search?q={$ckan_organization}&sort=metadata_modified+desc&rows=1";

    $this->stats++;

    $response = $this->curl->get($url);
    $body = json_decode($response, true);

    if (!isset($body['result']['count']) || !($count = $body['result']['count'])) {
      return;
    }

    //        skip if it would be the one sub-agency
    if ($count == $this->counts[trim($RootOrganization->getTitle())]) {
      return;
    }

    $my_post = array(
      'post_title' => $publisherTitle,
      'post_status' => 'publish',
      'post_type' => 'metric_new'
    );

    $content_id = wp_insert_post($my_post);

    $this->update_post_meta($content_id, 'metric_department_lvl', $parent_nid);

    $this->update_post_meta($content_id, 'metric_count', $count);

    //            http://catalog.data.gov/dataset?publisher=United+States+Mint.+Sales+and+Marketing+%28SAM%29+Department
    $this->update_post_meta(
      $content_id,
      'metric_url',
      $this->ckanUrl . "dataset?q={$ckan_organization}"
    );

    if ('Y' == $RootOrganization->getIsCfo()) {
      $this->update_post_meta($content_id, 'metric_sector', 'Federal Government');
      $organization_type = 'Federal Government';
    } else {
      $this->update_post_meta($content_id, 'metric_sector', 'Other Federal');
      $organization_type = 'Other Federal';
    }


    $this->update_post_meta($content_id, 'parent_organization', $parent_nid);

    $last_entry = '-';
    if (isset($body['result']) && isset($body['result']['results'])) {
      $last_entry = $body['result']['results'][0]['metadata_modified'];
      $last_entry = substr($last_entry, 0, 10);

      list($Y, $m, $d) = explode('-', $last_entry);
      $last_entry = "$m/$d/$Y";

      $this->update_post_meta($content_id, 'metric_last_entry', $last_entry);
    }

    $this->results[] = array($RootOrganization->getTitle(), trim($publisherTitle), $organization_type, $count, $last_entry);
  }

  /**
   * @param MetricsTaxonomy $RootOrganization
   * @param                   $parent_nid
   *
   * @return int
   */
  private function create_metric_content_by_publishers($RootOrganization, $parent_nid)
  {
    //        http://catalog.data.gov/api/action/package_search?q=organization:treasury-gov+AND+type:dataset&rows=0&facet.field=publisher
    $ckan_organization = 'organization:' . urlencode($RootOrganization->getTerm()) . '+AND+type:dataset';
    $url = $this->ckanApiUrl . "api/3/action/package_search?q={$ckan_organization}&rows=0&facet.field=[%22publisher%22]&facet.limit=200";

    $this->stats++;

    $response = $this->curl->get($url);
    $body = json_decode($response, true);

    if (!isset($body['result']['facets']['publisher'])) {
      return;
    }

    $publishers = $body['result']['facets']['publisher'];
    if (!sizeof($publishers)) {
      return;
    }

    ksort($publishers);

    foreach ($publishers as $publisherTitle => $count) {
      $my_post = array(
        'post_title' => $publisherTitle,
        'post_status' => 'publish',
        'post_type' => 'metric_new'
      );

      $content_id = wp_insert_post($my_post);

      $this->update_post_meta($content_id, 'metric_publisher', $parent_nid);

      $this->update_post_meta($content_id, 'metric_count', $count);

      //            http://catalog.data.gov/dataset?publisher=United+States+Mint.+Sales+and+Marketing+%28SAM%29+Department
      $this->update_post_meta(
        $content_id,
        'metric_url',
        $this->ckanUrl . "dataset?q={$ckan_organization}&publisher=" . urlencode($publisherTitle)
      );

      if ('Y' == $RootOrganization->getIsCfo()) {
        $this->update_post_meta($content_id, 'metric_sector', 'Federal Government');
        $organization_type = 'Federal Government';
      } else {
        $this->update_post_meta($content_id, 'metric_sector', 'Other Federal');
        $organization_type = 'Other Federal';
      }

      $this->update_post_meta($content_id, 'parent_organization', $parent_nid);

      //                http://catalog.data.gov/api/action/package_search?q=type:dataset+AND+extras_publisher:United+States+Mint.+Sales+and+Marketing+%28SAM%29+Department&sort=metadata_modified+desc&rows=1
      $apiPublisherTitle = str_replace(array('/', '%2F'), array('\/', '%5C%2F'), urlencode($publisherTitle));
      $url = $this->ckanApiUrl . "api/action/package_search?q={$ckan_organization}+AND+extras_publisher:" . $apiPublisherTitle . "&sort=metadata_modified+desc&rows=1";

      $this->stats++;

      $response = $this->curl->get($url);
      $body = json_decode($response, true);

      $last_entry = '-';
      if (isset($body['result']) && isset($body['result']['results'])) {
        $last_entry = $body['result']['results'][0]['metadata_modified'];
        $last_entry = substr($last_entry, 0, 10);

        list($Y, $m, $d) = explode('-', $last_entry);
        $last_entry = "$m/$d/$Y";

        $this->update_post_meta($content_id, 'metric_last_entry', $last_entry);
      }

      $this->results[] = array($RootOrganization->getTitle(), trim($publisherTitle), $organization_type, $count, $last_entry);
    }

    return;
  }

  /**
   *
   */
  private function write_metrics_csv_and_xls()
  {
    asort($this->results);

    $upload_dir = wp_upload_dir();

    $csvFilename = 'federal-agency-participation.csv';
    $csvPath = $upload_dir['basedir'] . '/' . $csvFilename;
    @chmod($csvPath, 0666);
    if (file_exists($csvPath) && !is_writable($csvPath)) {
      die('could not write ' . $csvPath);
    }

    //    Write CSV result file
    $fp_csv = fopen($csvPath, 'w');

    if ($fp_csv == false) {
      die("unable to create file");
    }

    fputcsv($fp_csv, array('Agency Name', 'Sub-Agency/Publisher', 'Organization Type', 'Datasets', 'Last Entry'));

    foreach ($this->results as $record) {
      fputcsv($fp_csv, $record);
    }
    fclose($fp_csv);

    @chmod($csvPath, 0666);

    if (!file_exists($csvPath)) {
      die('could not write ' . $csvPath);
    }

    $this->upload_to_s3($csvPath, $csvFilename);


    // Instantiate a new PHPExcel object
    $objPHPExcel = new PHPExcel();
    // Set the active Excel worksheet to sheet 0
    $objPHPExcel->setActiveSheetIndex(0);
    // Initialise the Excel row number
    $row = 1;

    $objPHPExcel->getActiveSheet()->SetCellValue('A' . $row, 'Agency Name');
    $objPHPExcel->getActiveSheet()->SetCellValue('B' . $row, 'Sub-Agency/Publisher');
    $objPHPExcel->getActiveSheet()->SetCellValue('C' . $row, 'Organization Type');
    $objPHPExcel->getActiveSheet()->SetCellValue('D' . $row, 'Datasets');
    $objPHPExcel->getActiveSheet()->SetCellValue('E' . $row, 'Last Entry');
    $row++;

    foreach ($this->results as $record) {
      if ($record) {
        $objPHPExcel->getActiveSheet()->SetCellValue('A' . $row, trim($record[0]));
        $objPHPExcel->getActiveSheet()->SetCellValue('B' . $row, trim($record[1]));
        $objPHPExcel->getActiveSheet()->SetCellValue('C' . $row, trim($record[2]));
        $objPHPExcel->getActiveSheet()->SetCellValue('D' . $row, $record[3]);
        $objPHPExcel->getActiveSheet()->SetCellValue('E' . $row, $record[4]);
        $row++;
      }
    }

    $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');

    $xlsFilename = 'federal-agency-participation.xlsx';
    $xlsPath = $upload_dir['basedir'] . '/' . $xlsFilename;
    @chmod($xlsPath, 0666);
    if (file_exists($xlsPath) && !is_writable($xlsPath)) {
      die('could not write ' . $xlsPath);
    }

    $objWriter->save($xlsPath);
    @chmod($xlsPath, 0666);

    if (!file_exists($xlsPath)) {
      die('could not write ' . $xlsPath);
    }

    $this->upload_to_s3($xlsPath, $xlsFilename);
  }

  /**
   * @param $from_local_path
   * @param $to_s3_path
   * @param string $acl
   */
  private function upload_to_s3($from_local_path, $to_s3_path, $acl = 'public-read')
  {
    if (WP_ENV !== 'production') {
      return;
    }
    // Create a service locator using a configuration file
    $aws = Aws::factory(array(
      'region' => 'us-east-1'
    ));

    // Get client instances from the service locator by name
    $s3 = $aws->get('s3');

    $s3_config = get_option('tantan_wordpress_s3');
    if (!$s3_config) {
      echo 's3 plugin is not configured';
      return;
    }

    $s3_bucket = $s3_config['bucket'];
    $s3_prefix = $s3_config['object-prefix'];

    //        avoiding tailing double-slash
    $s3_prefix = rtrim($s3_prefix, '/') . '/';

    //        avoiding prefix slash
    $to_s3_path = ltrim($to_s3_path, '/');

    // Upload a publicly accessible file. The file size and type are determined by the SDK.
    try {
      $s3->putObject([
        'Bucket' => $s3_bucket,
        'Key' => $s3_prefix . $to_s3_path,
        'Body' => fopen($from_local_path, 'r'),
        'ACL' => $acl,
      ]);
    } catch (Exception $e) {
      echo "There was an error uploading the file.\n";
      return;
    }
  }

}

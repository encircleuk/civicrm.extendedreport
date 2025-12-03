<?php

/**
 * Membership Price Pivot report
 *
 * This report pivots price-field options (from line items) into columns and
 * shows the summed amount per option for each membership (or contact, depending
 * on grouping).
 *
 * Place this file at:
 *  CRM/Extendedreport/Form/Report/Membership/MembershipPricePivot.php
 *
 * Then add the managed file above and run the extension upgrade to register.
 */

require_once 'CRM/Extendedreport/Form/Report/ExtendedReport.php';

class CRM_Extendedreport_Form_Report_Membership_MembershipPricePivot extends CRM_Extendedreport_Form_Report_ExtendedReport {

  protected $_summary = NULL;

  public function __construct() {
    parent::__construct();

    // Basic report metadata - tweak fields as required
    $this->_columns = [
      'civicrm_membership' => [
        'dao' => 'CRM_Member_DAO_Membership',
        'fields' => [
          'id' => ['title' => ts('Membership ID')],
          'membership_type_id' => ['title' => ts('Membership Type'), 'no_display' => TRUE],
          'status_id' => ['title' => ts('Membership Status'), 'no_display' => TRUE],
          'start_date' => ['title' => ts('Start Date'), 'no_display' => TRUE],
          'end_date' => ['title' => ts('End Date'), 'no_display' => TRUE],
        ],
      ],
      'civicrm_contact' => [
        'dao' => 'CRM_Contact_DAO_Contact',
        'fields' => [
          'display_name' => ['title' => ts('Contact')],
          'contact_id' => ['title' => ts('Contact ID'), 'no_display' => TRUE],
        ],
      ],
      // price option columns will be added dynamically to the root of _columns
    ];

    $this->_groupBy = ['civicrm_membership.id'];
    $this->_orderBy = 'civicrm_membership.id';
  }

  /**
   * Build the SELECT part of the query and dynamically add pivot columns.
   */
  public function select() {
    // Base select columns
    $this->_select = [
      "civicrm_membership.id as membership_id",
      "civicrm_contact.display_name as contact_display_name",
      "civicrm_membership.membership_type_id as membership_type_id",
      "civicrm_membership.status_id as membership_status_id",
      "civicrm_membership.start_date as membership_start_date",
      "civicrm_membership.end_date as membership_end_date",
    ];

    // Build dynamic pivot columns for price field options attached to memberships
    $this->buildPriceOptionColumns();
  }

  /**
   * Build FROM and JOIN clauses.
   */
  public function from() {
    // Core FROM and joins: membership -> contact, left join line_item -> price_field_value
    $this->_from = "
      FROM civicrm_membership
      LEFT JOIN civicrm_contact ON civicrm_contact.id = civicrm_membership.contact_id
      LEFT JOIN civicrm_line_item ON ( civicrm_line_item.entity_table = 'civicrm_membership' AND civicrm_line_item.entity_id = civicrm_membership.id )
      LEFT JOIN civicrm_price_field_value ON civicrm_price_field_value.id = civicrm_line_item.price_field_value_id
    ";
  }

  /**
   * Add GROUP BY clause.
   */
  public function groupBy() {
    // Group by membership to aggregate pivoted values per membership.
    $this->_groupBy = " GROUP BY civicrm_membership.id ";
  }

  /**
   * Build dynamic pivot columns based on distinct price field values used in membership line items.
   */
  protected function buildPriceOptionColumns() {
    $dao = CRM_Core_DAO::executeQuery("
      SELECT DISTINCT pfv.id, pfv.label, pfv.name
      FROM civicrm_price_field_value pfv
      JOIN civicrm_line_item li ON li.price_field_value_id = pfv.id
      WHERE li.entity_table = 'civicrm_membership'
      ORDER BY pfv.label
    ");

    while ($dao->fetch()) {
      $id = (int) $dao->id;
      // sanitized alias (avoid spaces/special chars)
      $alias = 'price_opt_' . $id;
      $label = $dao->label ? $dao->label : ('Option ' . $id);

      // Add SQL select column: sum of line_total for that price option
      // Use COALESCE to ensure 0 for empty
      $this->_select[] = "COALESCE(SUM(CASE WHEN civicrm_price_field_value.id = {$id} THEN civicrm_line_item.line_total ELSE 0 END),0) AS {$alias}";

      // Add column metadata so the report framework can display it and format
      $this->_columns['civicrm_price_field_value_' . $alias] = [
        'title' => ts('%1', [1 => $label]),
        'dbAlias' => $alias,
        'type' => CRM_Utils_Type::T_MONEY,
        'no_display' => FALSE,
        // ensure this column is included in export and display
        'export' => TRUE,
      ];
    }
  }

  /**
   * Post process: build and execute query and format rows.
   *
   * This uses the parent class mechanisms where available; we override minimally.
   */
  public function postProcess() {
    // Build the SQL pieces
    $this->select();
    $this->from();
    $this->groupBy();

    // Compose SQL
    $select = implode(",\n", $this->_select);
    $sql = "SELECT {$select} \n {$this->_from} \n {$this->_groupBy} ";

    // Apply any filters/where from framework (not implemented in this minimal sample)
    if (!empty($this->_where)) {
      $sql .= " WHERE " . implode(' AND ', $this->_where);
    }

    // Execute
    $dao = CRM_Core_DAO::executeQuery($sql);

    $rows = [];
    while ($dao->fetch()) {
      $row = [];
      // base fields
      $row['membership_id'] = $dao->membership_id;
      $row['contact_display_name'] = $dao->contact_display_name;
      $row['membership_type_id'] = $dao->membership_type_id;
      $row['membership_status_id'] = $dao->membership_status_id;
      $row['membership_start_date'] = $dao->membership_start_date;
      $row['membership_end_date'] = $dao->membership_end_date;

      // dynamic price option columns - fill from available columns in DAO
      foreach ($dao as $k => $v) {
        if (strpos($k, 'price_opt_') === 0) {
          // format amount (float)
          $row[$k] = (float) $v;
        }
      }

      $rows[] = $row;
    }

    // Store result for the report framework and apply display formatting
    $this->_rows = $rows;
    $this->alterDisplay($this->_rows);

    // The parent class expects to set template variables; mimic that minimal behaviour:
    $this->assign('rows', $this->_rows);
    $this->assign('sql', $sql);
  }

  /**
   * Format display values in rows (money formatting for pivot columns).
   */
  public function alterDisplay(&$rows) {
    if (empty($rows)) {
      return;
    }

    foreach ($rows as &$row) {
      foreach ($row as $k => $v) {
        if (strpos($k, 'price_opt_') === 0) {
          $row[$k] = CRM_Utils_Money::format($v, NULL, '%a %s'); // local currency formatting
        }
      }
    }
  }
}
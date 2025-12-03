<?php
// Managed file to register the Membership Price Pivot report.
return [
  0 => [
    'name' => 'Extended Report - Membership Price Pivot',
    'entity' => 'ReportTemplate',
    'params' => [
      'version' => 3,
      'label' => 'Extended Report - Membership Price Pivot',
      'description' => 'Pivot report showing membership line items (price field options) as columns with amounts',
      'class_name' => 'CRM_Extendedreport_Form_Report_Membership_MembershipPricePivot',
      'report_url' => 'membership/pricepivot',
      'component' => 'CiviMember',
    ],
  ],
];
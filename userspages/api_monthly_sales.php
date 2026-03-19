<?php
// api_monthly_sales.php
header('Content-Type: application/json');
require 'config.php';

// Last 6 calendar months including current month
$sql = "  SELECT  YEAR(created_at)  AS y,
    MONTH(created_at) AS m,
    DATE_FORMAT(created_at, '%b %Y') AS label,
    SUM(total) AS total_sales
  FROM orders
  WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
    AND created_at  < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
  GROUP BY y, m
  ORDER BY y, m
";
$res = mysqli_query($conn, $sql);

$rows = [];
while ($r = mysqli_fetch_assoc($res)) {
  $rows[] = $r;
}

// Normalize: ensure 6 months sequence with zeros for missing months
$map = [];
foreach ($rows as $r) {
  $ym = sprintf('%04d-%02d', $r['y'], $r['m']);
  $map[$ym] = [
    'label' => $r['label'],
    'sum'   => (float)$r['total_sales']
  ];
}

$labels = [];
$values = [];
$start = new DateTime('first day of -5 month');
$end   = new DateTime('first day of this month');

for ($d = clone $start; $d <= $end; $d->modify('+1 month')) {
  $ym = $d->format('Y-m');
  if (isset($map[$ym])) {
    $labels[] = $map[$ym]['label'];
    $values[] = $map[$ym]['sum'];
  } else {
    $labels[] = $d->format('M Y');
    $values[] = 0.0;
  }
}

echo json_encode(['labels' => $labels, 'data' => $values]);

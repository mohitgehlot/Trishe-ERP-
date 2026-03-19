<?php
include 'config.php';
header('Content-Type: application/json');

$key = trim($_GET['key'] ?? '');
$type = $_GET['type'] ?? 'phone';

if ($key===''){ echo json_encode(['found'=>false]); exit; }

if ($type==='email') $stmt=$conn->prepare("SELECT id,name,phone,email,address FROM customers WHERE email=? LIMIT 1");
else $stmt=$conn->prepare("SELECT id,name,phone,email,address FROM customers WHERE phone=? LIMIT 1");

$stmt->bind_param('s',$key);
$stmt->execute();
$res=$stmt->get_result();
if ($row=$res->fetch_assoc()) echo json_encode(['found'=>true,'customer'=>$row]);
else echo json_encode(['found'=>false]);

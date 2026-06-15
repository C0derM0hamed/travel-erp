<?php
$ch = curl_init('http://127.0.0.1:8000/storage/office-logos/1/01d48c01-66dd-4f36-83d0-33281c28be6f.png');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$response = curl_exec($ch);
echo $response;

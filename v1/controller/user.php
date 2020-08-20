<?php

require_once('../service/Database.php');
require_once('../model/Response.php');

try {
    $writeDb = Database::connectWriteDb();
} catch (PDOException $exception) {
    error_log("Connection error - " . $exception, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection Error!");
    $response->send();
    exit();
}

//handle options request method for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    $response = new Response();
    $response->setHttpStatusCode(200);
    $response->setSuccess(true);
    $response->send();
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request Method not Alowed!");
    $response->send();
    exit();
}

if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] !== 'application/json') {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Content type header is not set to json");
    $response->send();
    exit();
}

$rawPostData = file_get_contents('php://input');
if (!$jsonData = json_decode($rawPostData)) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Request body is not VALID json");
    $response->send();

    exit();
}

if (!isset($jsonData->fullname) || !isset($jsonData->username) || !isset($jsonData->password)) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    !isset($jsonData->fullname) ? $response->addMessage("Fulame field is mandatory and must be provided!") : false;
    !isset($jsonData->username) ? $response->addMessage("Username field is mandatory and must be provided!") : false;
    !isset($jsonData->password) ? $response->addMessage("PAssword field is mandatory and must be provided!") : false;
    $response->send();

    exit();
}

if ((strlen($jsonData->fullname) < 1 || strlen($jsonData->fullname) > 255) ||
    (strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255) ||
    (strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255)) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    (strlen($jsonData->fullname) < 1) ? $response->addMessage("Full name field is  mandatory and must have 1 character!") : false;
    (strlen($jsonData->fullname) > 255) ? $response->addMessage("Full name field cannot be greater than 255 characters!") : false;
    (strlen($jsonData->username) < 1) ? $response->addMessage("Username field is mandatory and must be provided!") : false;
    (strlen($jsonData->username) > 255) ? $response->addMessage("Username field cannot be greater than 255 characters!") : false;
    (strlen($jsonData->password) < 1) ? $response->addMessage("Password field is mandatory and must be provided!") : false;
    (strlen($jsonData->password) > 255) ? $response->addMessage("Password field cannot be greater than 255 characters!") : false;
    $response->send();

    exit();
}

$fullName = trim($jsonData->fullname);
$userName = trim($jsonData->username);
$password = $jsonData->password;

try {
    $query = $writeDb->prepare('SELECT id from tblusers WHERE username =:userName');
    $query->bindParam(':userName', $userName, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if ($rowCount !== 0) {
        $response = new Response();
        $response->setHttpStatusCode(409);
        $response->setSuccess(false);
        $response->addMessage("UserName already exists!");
        $response->send();

        exit();
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $query = $writeDb->prepare('INSERT INTO tblusers (fullname, username, password)
                         VALUES (:fullname, :username, :password)');
    $query->bindParam(':fullname', $fullName, PDO::PARAM_STR);
    $query->bindParam(':username', $userName, PDO::PARAM_STR);
    $query->bindParam(':password', $hashed_password, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if ($rowCount === 0) {
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("Failed to create user row count - check submitted data for errors!!");
        $response->send();

        exit();
    }

    $lastUSerId = $writeDb->lastInsertId();

    $returnData = [];
    $returnData['user_id'] = $lastUSerId;
    $returnData['fullname'] = $fullName;
    $returnData['username'] = $userName;

    $response = new Response();
    $response->setHttpStatusCode(201);
    $response->setSuccess(true);
    $response->addMessage("User created");
    $response->setData($returnData);
    $response->send();

    exit();

} catch (PDOException $exception) {
    error_log("Database query error - " . $exception, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Failed to create user - check submitted data for errors!");
    $response->send();

    exit();
}
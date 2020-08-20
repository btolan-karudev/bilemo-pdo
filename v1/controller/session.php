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

if (array_key_exists("sessionid", $_GET)) {

    $sessionId = $_GET['sessionid'];

    if ($sessionId === '' || !is_numeric($sessionId)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $sessionId === '' ? $response->addMessage("Session ID cannot be blank!") : false;
        !is_numeric($sessionId) ? $response->addMessage("Session ID must be numeric!") : false;
        $response->send();

        exit();
    }

    if ((!isset($_SERVER['HTTP_AUTHORIZATION'])) || (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1)) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage("Access Token is missing from the header!") : false);
        (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access Token cannot be blank!") : false);
        $response->send();

        exit();
    }

    $accessToken = $_SERVER['HTTP_AUTHORIZATION'];

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        try {

            $query = $writeDb->prepare('DELETE from tblsessions WHERE id = :sessionId 
                                        AND accestoken =:accesToken');
            $query->bindParam(':sessionId', $sessionId, PDO::PARAM_INT);
            $query->bindParam(':accesToken', $accessToken, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Failed to log out using the acces token provided!");
                $response->send();
                exit();
            }

            $returnData = [];
            $returnData['session_id'] = intval($sessionId);

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Logged Out!");
            $response->setData($returnData);
            $response->send();
            exit();

        } catch (PDOException $exception) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("There was an issue logging out - please try again!");
            $response->send();

            exit();
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {

        if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] !== 'application/json') {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Content type header is not set to JSON");
            $response->send();
            exit();
        }

        $rawPatchData = file_get_contents('php://input');

        if (!$jsonData = json_decode($rawPatchData)) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Request body is not VALID json");
            $response->send();
            exit();
        }

        if (!isset($jsonData->refresh_token) || strlen($jsonData->refresh_token) < 1) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            (!isset($jsonData->refresh_token) ? $response->addMessage("Refresh Token not supplied") : false);
            (strlen($jsonData->refresh_token) < 1 ? $response->addMessage("Refresh Token cannot be blank") : false);
            $response->send();
            exit();
        }

        try {
            $refreshToken = $jsonData->refresh_token;

            $query = $writeDb->prepare('SELECT tblsessions.id as sessionid, tblsessions.userid as userid,
             accestoken, refreshtoken, useractive, loginattempts, accestokenexpiry, refreshtokenexpiry FROM
             tblsessions, tblusers WHERE tblusers.id = tblsessions.userid and tblsessions.id = :sessionid and 
             tblsessions.accestoken = :accestoken and tblsessions.refreshtoken = :refreshtoken
             ');
            $query->bindParam(':sessionid', $sessionId, PDO::PARAM_INT);
            $query->bindParam(':accestoken', $accessToken, PDO::PARAM_STR);
            $query->bindParam(':refreshtoken', $refreshToken, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Access token or refresh token is incorrect for session Id!");
                $response->send();
                exit();
            }

            $row = $query->fetch(PDO::FETCH_ASSOC);

            $returned_sessionid = $row['sessionid'];
            $returned_userid = $row['userid'];
            $returned_accestoken = $row['accestoken'];
            $returned_refreshtoken = $row['refreshtoken'];
            $returned_useractive = $row['useractive'];
            $returned_loginattempts = $row['loginattempts'];
            $returned_accestokenexpiry = $row['accestokenexpiry'];
            $returned_refreshtokenexpiry = $row['refreshtokenexpiry'];

            if ($returned_useractive !== 'Y') {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("User account is not active!");
                $response->send();
                exit();
            }

            if ($returned_loginattempts >= 3) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("User account is currently locked out!");
                $response->send();
                exit();
            }

            if (strtotime($returned_refreshtokenexpiry) < time()) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Refresh token has expired - please log in again!");
                $response->send();
                exit();
            }

            $accessToken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)) . time());
            $refreshToken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)) . time());

            $access_token_expiry_seconds = 1200;
            $refresh_token_expiry_seconds = 1209600;

            $query = $writeDb->prepare('UPDATE tblsessions SET accestoken = :accestoken,
            accestokenexpiry = date_add(now(), INTERVAL :accestokenexpiryseconds SECOND), refreshtoken = :refreshtoken,
            refreshtokenexpiry = date_add(now(), INTERVAL :refreshtokenexpiryseconds SECOND) WHERE id = :sessionid and
            userid = :userid and accestoken = :returnedaccestoken and refreshtoken = :returnedrefreshtoken
            ');
            $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $query->bindParam(':sessionid', $returned_sessionid, PDO::PARAM_INT);
            $query->bindParam(':accestoken', $accessToken, PDO::PARAM_STR);
            $query->bindParam(':accestokenexpiryseconds', $access_token_expiry_seconds, PDO::PARAM_INT);
            $query->bindParam(':refreshtoken', $refreshToken, PDO::PARAM_STR);
            $query->bindParam(':refreshtokenexpiryseconds', $refresh_token_expiry_seconds, PDO::PARAM_INT);
            $query->bindParam(':returnedaccestoken', $returned_accestoken, PDO::PARAM_STR);
            $query->bindParam(':returnedrefreshtoken', $returned_refreshtoken, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Access token could not be refresh - please log in again!");
                $response->send();
                exit();
            }

            $returnData = [];
            $returnData['session_id'] = $returned_sessionid;
            $returnData['access_token'] = $accessToken;
            $returnData['access_token_expires_in'] = $access_token_expiry_seconds;
            $returnData['refresh_token'] = $refreshToken;
            $returnData['refresh_token_expires_in'] = $refresh_token_expiry_seconds;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Token refreshed successfully");
            $response->setData($returnData);
            $response->send();
            exit();

        } catch (PDOException $exception) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("There was an issue refreshing access token - Please log in again!");
            $response->send();
            exit();
        }


    } else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed!");
        $response->send();
        exit();
    }
} elseif (empty($_GET)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed!");
        $response->send();
        exit();
    }

    sleep(2);

    if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] !== 'application/json') {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Content type header is not set to JSON");
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

    if (!isset($jsonData->username) || !isset($jsonData->password)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        !isset($jsonData->username) ? $response->addMessage("Username not supplied!") : false;
        !isset($jsonData->password) ? $response->addMessage("Password not supplied!") : false;
        $response->send();
        exit();
    }

    if ((strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255) ||
        (strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        (strlen($jsonData->username) < 1) ? $response->addMessage("Username cannot be blank!") : false;
        (strlen($jsonData->username) > 255) ? $response->addMessage("Username field cannot be greater than 255 characters!") : false;
        (strlen($jsonData->password) < 1) ? $response->addMessage("Password field cannot be blank!") : false;
        (strlen($jsonData->password) > 255) ? $response->addMessage("Password field cannot be greater than 255 characters!") : false;
        $response->send();
        exit();
    }

    try {
        $username = $jsonData->username;
        $password = $jsonData->password;

        $query = $writeDb->prepare('SELECT id, fullname, username, password, useractive, loginattempts from
                         tblusers WHERE username =:username');
        $query->bindParam(':username', $username, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("Username or password is incorrect!");
            $response->send();
            exit();
        }

        $row = $query->fetch(PDO::FETCH_ASSOC);

        $returned_id = $row['id'];
        $returned_fullname = $row['fullname'];
        $returned_username = $row['username'];
        $returned_password = $row['password'];
        $returned_useractive = $row['useractive'];
        $returned_loginattempts = $row['loginattempts'];

        if ($returned_useractive !== 'Y') {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("User account not active!");
            $response->send();
            exit();
        }

        if ($returned_loginattempts >= 3) {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("User account is currently locked out!");
            $response->send();
            exit();
        }

        if (!password_verify($password, $returned_password)) {
            $query = $writeDb->prepare('UPDATE tblusers SET loginattempts = loginattempts+1 WHERE id = :id');
            $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
            $query->execute();

            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("Username or password is incorrect!");
            $response->send();
            exit();
        }

        $accessToken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)) . time());
        $refreshToken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)) . time());

        $access_token_expiry_seconds = 1200;
        $refresh_token_expiry_seconds = 1209600;  //40 days


    } catch (PDOException $exception) {
        error_log("Database query error - " . $exception, 0);
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("There was an issue login in!");
        $response->send();
        exit();
    }

    try {
        $writeDb->beginTransaction();

        $query = $writeDb->prepare('UPDATE tblusers SET loginattempts = 0 WHERE id = :id');
        $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
        $query->execute();

        $query = $writeDb->prepare('INSERT INTO tblsessions (userid, accestoken, accestokenexpiry,
                            refreshtoken, refreshtokenexpiry)
                            VALUES (:userid, :accestoken, date_add(now(), INTERVAL :accestokenexpiryseconds SECOND),
                            :refreshtoken, date_add(now(), INTERVAL :refreshtokenexpiryseconds SECOND))');
        $query->bindParam(':userid', $returned_id, PDO::PARAM_INT);
        $query->bindParam(':accestoken', $accessToken, PDO::PARAM_STR);
        $query->bindParam(':accestokenexpiryseconds', $access_token_expiry_seconds, PDO::PARAM_INT);
        $query->bindParam(':refreshtoken', $refreshToken, PDO::PARAM_STR);
        $query->bindParam(':refreshtokenexpiryseconds', $refresh_token_expiry_seconds, PDO::PARAM_INT);
        $query->execute();

        $lastSessionID = $writeDb->lastInsertId();

        $writeDb->commit();

        $returnData = [];
        $returnData['session_id'] = intval($lastSessionID);
        $returnData['access_token'] = $accessToken;
        $returnData['access_token_expires_in'] = $access_token_expiry_seconds;
        $returnData['refresh_token'] = $refreshToken;
        $returnData['refresh_token_expires_in'] = $refresh_token_expiry_seconds;

        $response = new Response();
        $response->setHttpStatusCode(201);
        $response->setSuccess(true);
        $response->addMessage("Session created");
        $response->setData($returnData);
        $response->send();
        exit();
    } catch (PDOException $exception) {
        error_log("Database query error - " . $exception, 0);
        $writeDb->rollBack();
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("There was an issue login in!Please try again");
        $response->send();
    }
} else {
    $response = new Response();
    $response->setHttpStatusCode(404);
    $response->setSuccess(false);
    $response->addMessage("Endpoint not found!");
    $response->send();
    exit();
}
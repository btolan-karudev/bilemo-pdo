<?php

require_once('../service/Database.php');
require_once('../model/Customer.php');
require_once('../model/Response.php');

try {
    $writeDb = Database::connectWriteDb();
    $readDb = Database::connectReadDb();
} catch (PDOException $exception) {
    error_log("Connection error - " . $exception, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection Error!");
    $response->send();
    exit();
}

//begin auth script

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

try {
    $query = $writeDb->prepare('SELECT userid, accestokenexpiry, useractive, loginattempts 
                                        FROM tblsessions, tblusers
                                        WHERE tblsessions.userid = tblusers.id AND accestoken =:accesToken');
    $query->bindParam(':accesToken', $accessToken, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if ($rowCount === 0) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Invalid access token provided!");
        $response->send();
        exit();
    }

    $row = $query->fetch(PDO::FETCH_ASSOC);

    $returned_userid = $row['userid'];
    $returned_useractive = $row['useractive'];
    $returned_loginattempts = $row['loginattempts'];
    $returned_accestokenexpiry = $row['accestokenexpiry'];


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

    if (strtotime($returned_accestokenexpiry) < time()) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Token has expired - please log in again!");
        $response->send();
        exit();
    }
} catch (PDOException $exception) {
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("There was an issue authenticating - Please log in again!");
    $response->send();
    exit();
}

//end auth script

if (array_key_exists("customerId", $_GET)) {

    $customerId = $_GET['customerId'];

    if ($customerId == '' || !is_numeric($customerId)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Customer ID must be numeric and cannot be blank!");
        $response->send();
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        try {
            $query = $readDb->prepare('SELECT id, first_name, last_name,
            DATE_FORMAT(created_at, "%d/%m/%Y %H:%i") as created_at,
            status from tblcustomers WHERE id =:customerId AND userid =:userId');
            $query->bindParam(':customerId', $customerId, PDO::PARAM_INT);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Customer not Found!");
                $response->send();
                exit();
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $customer = new Customer($row['id'], $row['first_name'], $row['last_name'], $row['created_at'], $row['status']);

                $customersArray[] = $customer->returnCustomerAsArray();
            }
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['customers'] = $customersArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();

        } catch (CustomerException $customerException) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($customerException->getMessage());
            $response->send();
            exit();
        } catch (PDOException $exception) {
            error_log("Database query error - " . $exception, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Customer!");
            $response->send();
            exit();
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

        try {
            $query = $writeDb->prepare('DELETE from tblcustomers WHERE id = :customerId AND userid =:userId');
            $query->bindParam(':customerId', $customerId, PDO::PARAM_INT);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Customer not Found!");
                $response->send();
                exit();

            }
            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Customer Deleted!");
            $response->send();
            exit();

        } catch (PDOException $exception) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to delete customer!");
            $response->send();

            exit();
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {

        try {
            if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] !== 'application/json') {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Content type header is not set to json");
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

            $first_name_updated = false;
            $last_name_updated = false;
            $created_at_updated = false;
            $status_updated = false;

            $queryFields = "";

            if (isset($jsonData->first_name)) {
                $first_name_updated = true;
                $queryFields .= "first_name = :first_name, ";
            }

            if (isset($jsonData->last_name)) {
                $last_name_updated = true;
                $queryFields .= "last_name = :last_name, ";
            }

            if (isset($jsonData->created_at)) {
                $created_at_updated = true;
                $queryFields .= "created_at = STR_TO_DATE(:created_at, '%d/%m/%Y %H:%i'), ";
            }

            if (isset($jsonData->status)) {
                $status_updated = true;
                $queryFields .= "status = :status, ";
            }

            $queryFields = rtrim($queryFields, ", ");

            if ($first_name_updated === false && $last_name_updated === false &&
                $created_at_updated === false && $status_updated === false) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("No customer fields provided to change!");
                $response->send();
                exit();
            }

            $query = $writeDb->prepare('SELECT id, first_name, last_name,
            DATE_FORMAT(created_at, "%d/%m/%Y %H:%i") as created_at,
            status from tblcustomers WHERE id =:customerId AND userid =:userId');
            $query->bindParam(':customerId', $customerId, PDO::PARAM_INT);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Update Customer not Found!");
                $response->send();

                exit();
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $customer = new Customer($row['id'], $row['first_name'], $row['last_name'], $row['created_at'], $row['status']);
            }

            $queryString = "UPDATE tblcustomers SET " . $queryFields . " WHERE id = :customerId AND userid =:userId";
            $query = $writeDb->prepare($queryString);

            if ($first_name_updated === true) {
                $customer->setFirstName($jsonData->first_name);
                $up_first_name = $customer->getFirstName();
                $query->bindParam(":first_name", $up_first_name, PDO::PARAM_STR);
            }

            if ($last_name_updated === true) {
                $customer->setLastName($jsonData->last_name);
                $up_last_name = $customer->getLastName();
                $query->bindParam(":last_name", $up_last_name, PDO::PARAM_STR);
            }

            if ($created_at_updated === true) {
                $customer->setCreatedAt($jsonData->created_at);
                $up_created_at = $customer->getCreatedAt();
                $query->bindParam(":created_at",  $up_created_at, PDO::PARAM_STR);
            }

            if ($status_updated === true) {
                $customer->setStatus($jsonData->status);
                $up_status = $customer->getStatus();
                $query->bindParam(":status", $up_status, PDO::PARAM_STR);
            }



            $query->bindParam(":customerId", $customerId, PDO::PARAM_INT);
            $query->bindParam(":userID", $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Failed to update customer!");
                $response->send();

                exit();
            }

            $query = $writeDb->prepare('SELECT id, first_name, last_name,
            DATE_FORMAT(created_at, "%d/%m/%Y %H:%i") as created_at,
            status from tblcustomers WHERE id =:customerId AND userid =:userId');
            $query->bindParam(':customerId', $customerId, PDO::PARAM_INT);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Customer not Found after update!");
                $response->send();

                exit();
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $customer = new Customer($row['id'], $row['first_name'], $row['last_name'], $row['created_at'], $row['status']);

                $customersArray[] = $customer->returnCustomerAsArray();
            }
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['customers'] = $customersArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Customer updated successfully");
            $response->setData($returnData);
            $response->send();

            exit();

        } catch (CustomerException $customerException) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($customerException->getMessage());
            $response->send();

            exit();

        } catch (PDOException $exception) {
            error_log("Database query error - " . $exception, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to update customer into database - check submitted data for errors!");
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

} elseif (array_key_exists("status", $_GET)) {

    $status = $_GET['status'];

    if ($status !== 'YES' && $status !== 'NO') {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Status must be 'YES' or 'NO'");
        $response->send();
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        try {
            $query = $readDb->prepare('SELECT id, first_name, last_name,
            DATE_FORMAT(created_at, "%d/%m/%Y %H:%i") as created_at,
            status from tblcustomers WHERE status =:status AND userid =:userId');
            $query->bindParam(':status', $status, PDO::PARAM_INT);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Customer not Found!");
                $response->send();
                exit();
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $customer = new Customer($row['id'], $row['first_name'], $row['last_name'], $row['created_at'], $row['status']);

                $customersArray[] = $customer->returnCustomerAsArray();
            }
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['customers'] = $customersArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();
        } catch (CustomerException $customerException) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($customerException->getMessage());
            $response->send();
            exit();
        } catch (PDOException $exception) {
            error_log("Database query error - " . $exception, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Customer!");
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
} elseif (array_key_exists("page", $_GET)) {

    $page = $_GET['page'];

    if ($page == '' || !is_numeric($page)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Page must be numeric and cannot be blank!");
        $response->send();
        exit();
    }

    $limitPerPage = 5;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        try {
            $query = $readDb->prepare('SELECT count(id) as totalNoOfCustomers from tblcustomers WHERE userid =:userId');
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $row = $query->fetch(PDO::FETCH_ASSOC);
            $customersCount = intval($row['totalNoOfCustomers']);

            $numOfPages = ceil($customersCount / $limitPerPage);

            if ($numOfPages == 0) {
                $numOfPages = 1;
            }

            if ($page > $numOfPages) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Page Not Found!");
                $response->send();
                exit();
            }

            $offset = ($page == 1 ? 0 : ($limitPerPage * ($page - 1)));

            $query = $readDb->prepare('SELECT id, first_name, last_name,
            DATE_FORMAT(created_at, "%d/%m/%Y %H:%i") as created_at,
            status from tblcustomers WHERE userid =:userId
            LIMIT :pglimit OFFSET :offset');
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->bindParam(':pglimit', $limitPerPage, PDO::PARAM_INT);
            $query->bindParam(':offset', $offset, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $customer = new Customer($row['id'], $row['first_name'], $row['last_name'], $row['created_at'], $row['status']);

                $customersArray[] = $customer->returnCustomerAsArray();
            }

            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['total_rows'] = $customersCount;
            $returnData['total_pages'] = $numOfPages;
            ($page < $numOfPages ? $returnData['has_next_page'] = true : $returnData['has_next_page'] = false);
            ($page > 1 ? $returnData['has_previous_page'] = true : $returnData['has_previous_page'] = false);
            $returnData['customers'] = $customersArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();
        } catch (CustomerException $customerException) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($customerException->getMessage());
            $response->send();
            exit();
        } catch (PDOException $exception) {
            error_log("Database query error - " . $exception, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Customers!");
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

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        try {
            $query = $readDb->prepare('SELECT id, first_name, last_name,
            DATE_FORMAT(created_at, "%d/%m/%Y %H:%i") as created_at,
            status from tblcustomers WHERE userid =:userId');
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Customers not Found!");
                $response->send();
                exit();
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $customer = new Customer($row['id'], $row['first_name'], $row['last_name'], $row['created_at'], $row['status']);

                $customersArray[] = $customer->returnCustomerAsArray();
            }
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['customers'] = $customersArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();
        } catch (CustomerException $customerException) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($customerException->getMessage());
            $response->send();
            exit();
        } catch (PDOException $exception) {
            error_log("Database query error - " . $exception, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Customers!");
            $response->send();
            exit();
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

        try {
            if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] != 'application/json') {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Content type header is not set to json");
                $response->send();
                exit();
            }

            $rawPOSTData = file_get_contents('php://input');

            if (!$jsonData = json_decode($rawPOSTData)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Request body is not VALID json");
                $response->send();
                exit();
            }

            if (!isset($jsonData->first_name) || !isset($jsonData->status)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                !isset($jsonData->first_name) ? $response->addMessage("First Name field is mandatory and must be provided!") : false;
                !isset($jsonData->status) ? $response->addMessage("Status field is mandatory and must be provided!") : false;
                $response->send();
                exit();
            }

            $newCustomer = new Customer(null, $jsonData->first_name,
                (isset($jsonData->last_name) ? $jsonData->last_name : null),
                (isset($jsonData->created_at) ? $jsonData->created_at : null),
                $jsonData->status);

            $firstName = $newCustomer->getFirstName();
            $lastName = $newCustomer->getLastName();
            $createdAt = $newCustomer->getCreatedAt();
            $status = $newCustomer->getStatus();

            $query = $writeDb->prepare("INSERT INTO tblcustomers
                    (first_name, last_name, created_at, status, userid)
                    VALUES (:first_name, :last_name, STR_TO_DATE(:created_at, '%d/%m/%Y %H:%i'), :status, :userId)");
            $query->bindParam(':first_name', $firstName, PDO::PARAM_STR);
            $query->bindParam(':last_name', $lastName, PDO::PARAM_STR);
            $query->bindParam(':created_at', $createdAt, PDO::PARAM_STR);
            $query->bindParam(':status', $status, PDO::PARAM_STR);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Failed to create customer!");
                $response->send();
                exit();
            }

            $lastCustomerID = $writeDb->lastInsertId();

            $query = $writeDb->prepare('SELECT id, first_name, last_name,
            DATE_FORMAT(created_at, "%d/%m/%Y %H:%i") as created_at,
            status from tblcustomers WHERE id =:lastCustomerID AND userid = :userId');
            $query->bindParam(':lastCustomerID', $lastCustomerID, PDO::PARAM_INT);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage("Failed to retrieve customer after creation!");
                $response->send();
                exit();
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $customer = new Customer($row['id'], $row['first_name'], $row['last_name'], $row['created_at'], $row['status']);

                $customersArray[] = $customer->returnCustomerAsArray();
            }
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['customers'] = $customersArray;

            $response = new Response();
            $response->setHttpStatusCode(201);
            $response->setSuccess(true);
            $response->addMessage("Customer created successfully");
            $response->setData($returnData);
            $response->send();
            exit();
        } catch (CustomerException $customerException) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($customerException->getMessage());
            $response->send();
            exit();
        } catch (PDOException $exception) {
            error_log("Database query error - " . $exception, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to insert customer into database - check submitted data for errors!");
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
} else {
    $response = new Response();
    $response->setHttpStatusCode(404);
    $response->setSuccess(false);
    $response->addMessage("Endpoint not found!");
    $response->send();
    exit();
}
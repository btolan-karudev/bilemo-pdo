<?php

class CustomerException extends Exception
{
}

class Customer
{
    private $_id;
    private $_firstName;
    private $_lastName;
    private $_createdAt;
    private $_status;

    public function __construct($id, $firstName, $lastName, $createdAt, $status)
    {
        $this->setId($id);
        $this->setFirstName($firstName);
        $this->setLastName($lastName);
        $this->setCreatedAt($createdAt);
        $this->setStatus($status);
    }

    public function getId()
    {
        return $this->_id;
    }

    public function setId($id): void
    {
        if (($id !== null) && (!is_numeric($id) || $id <= 0 || $id > 9223372036854775807 || $this->_id !== null)) {
            throw new CustomerException("Customer ID error");
        }

        $this->_id = $id;
    }

    public function getFirstName()
    {
        return $this->_firstName;
    }

    public function setFirstName($firstName): void
    {
        if (strlen($firstName) < 0 || strlen($firstName) > 255) {
            throw new CustomerException("Customer firstName error");
        }

        $this->_firstName = $firstName;
    }

    public function getLastName()
    {
        return $this->_lastName;
    }

    public function setLastName($lastName): void
    {
        if (($lastName !== null) && (strlen($lastName) > 16777215)) {
            throw new CustomerException("Customer lastName error");
        }

        $this->_lastName = $lastName;
    }

    public function getCreatedAt()
    {
        return $this->_createdAt;
    }

    public function setCreatedAt($createdAt): void
    {
        if (($createdAt !== null) && (date_format(date_create_from_format('d/m/Y H:i', $createdAt), 'd/m/Y H:i') != $createdAt)) {
            throw new CustomerException("Customer createdAt date time error");
        }

        $this->_createdAt = $createdAt;
    }

    public function getStatus()
    {
        return $this->_status;
    }

    public function setStatus($status): void
    {
        if ((strtoupper($status) !== 'YES') && (strtoupper($status) !== 'NO')) {
            throw new CustomerException("Customer status must be YES or NO");
        }

        $this->_status = $status;
    }

    public function returnCustomerAsArray()
    {
        $customer = [];
        $customer['id'] = $this->getId();
        $customer['firstName'] = $this->getFirstName();
        $customer['lastName'] = $this->getLastName();
        $customer['createdAt'] = $this->getCreatedAt();
        $customer['status'] = $this->getStatus();

        return $customer;
    }

}
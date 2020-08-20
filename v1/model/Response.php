<?php


class Response
{
    private $_success;
    private $_httpStatusCode;
    private $_messages = [];
    private $_data;
    private $_toCache = false;
    private $_responseData = [];


    public function getSuccess()
    {
        return $this->_success;
    }

    public function setSuccess($success)
    {
        $this->_success = $success;
    }

    public function getHttpStatusCode()
    {
        return $this->_httpStatusCode;
    }

    public function setHttpStatusCode($httpStatusCode)
    {
        $this->_httpStatusCode = $httpStatusCode;
    }

    public function getMessages()
    {
        return $this->_messages;
    }

    public function addMessage($message)
    {
        $this->_messages[] = $message;
    }

    public function getData()
    {
        return $this->_data;
    }

    public function setData($data)
    {
        $this->_data = $data;
    }

    public function isToCache()
    {
        return $this->_toCache;
    }

    public function toCache($toCache)
    {
        $this->_toCache = $toCache;
    }

    public function send()
    {
        header('Content-Type: application/json;charset=UTF-8');

        if ($this->isToCache()) {
            header('Cache-Control: max-age=60');
        } else {
            header('Cache-Control: no-cache, no-store');
        }

        if (($this->getSuccess() && !$this->getSuccess()) || !is_numeric($this->getHttpStatusCode())) {
            http_response_code(500);
            $this->_responseData['statusCode'] = 500;
            $this->_responseData['success'] = 200;
            $this->addMessage("Response creation error");
            $this->_responseData['messages'] = $this->getMessages();
        } else {
            http_response_code($this->getHttpStatusCode());
            $this->_responseData['statusCode'] = $this->getHttpStatusCode();
            $this->_responseData['success'] = $this->getSuccess();
            $this->_responseData['messages'] = $this->getMessages();
            $this->_responseData['data'] = $this->getData();
        }

        echo json_encode($this->_responseData);
    }

}
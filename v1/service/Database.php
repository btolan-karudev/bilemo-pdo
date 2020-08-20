<?php


class Database
{
    private static $writeConnDb;
    private static $readConnDb;

    public static function connectWriteDb()
    {
        if (self::$writeConnDb === null) {
            self::$writeConnDb = new PDO('mysql:dbname=bilemopdo;host=localhost;charset=utf8', 'root', '');
            self::$writeConnDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$writeConnDb->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }

        return self::$writeConnDb;
    }

    public static function connectReadDb()
    {
        if (self::$readConnDb === null) {
            self::$readConnDb = new PDO('mysql:dbname=bilemopdo;host=localhost;charset=utf8', 'root', '');
            self::$readConnDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$readConnDb->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }

        return self::$readConnDb;
    }
}
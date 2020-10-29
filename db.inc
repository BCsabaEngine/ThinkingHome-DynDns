<?php
    include_once('config.inc');
    include_once('common.inc');
    
    global $db;

    $dsn = "mysql:host=localhost;dbname=" . DBNAME;
    $options = [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => true,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ];
    $db = new PDO($dsn, DBUSER, DBPASS, $options);

    function DBPrepareParams($params)
    {
        $result = [];
        if ($params)
            foreach($params as $pkey => $pvalue)
            {
                $matches = array();
                if (preg_match('/^:\w+\[(\d+)\]$/', $pkey, $matches))
                {
                    $maxlength = $matches[1];
                    $pkey = str_replace("[$maxlength]", '', $pkey);
                    if (mb_strlen($pvalue) > $maxlength)
                        $pvalue = mb_substr($pvalue, 0, $maxlength);
                }
                $result[$pkey] = $pvalue;
            }
        return $result;            
    }

    function DBBegin()
    {
        global $db;

        $db->beginTransaction();
    }

    function DBCommit()
    {
        global $db;

        $db->commit();
    }

    function DBRollback()
    {
        global $db;

        $db->rollBack();
    }
    
    function DBTran($func)
    {
        if (!$func)
            return;

        global $db;

        DBBegin();
        try
        {
            $func();
            DBCommit();
        }
        catch (exception $ex)
        {
            DBRollback();
            throw $ex;
        }
    }

    function DBInTransaction()
    {
        global $db;

        return $db->inTransaction();
    }

    function DBSelect($query, array $params = null)
    {
        global $db;

        $musttran = !DBInTransaction();
        
        $result = [];

        if ($musttran)
            DBBegin();
        $stmt = $db->prepare($query);
        $stmt->execute($params);

        while ($row = $stmt->fetch(PDO::FETCH_OBJ))
            $result[] = $row;
        $stmt->closeCursor();
        
        if ($musttran)
            DBCommit();
        unset($stmt);

        return $result;
    }

    function DBStatement($query, array $params = null)
    {
        global $db;

        $musttran = !(DBInTransaction());

        if ($musttran) DBBegin();

        $stmt = $db->prepare($query);
        $stmt->execute(DBPrepareParams($params));
        $lastinsert = $db->lastInsertId();

        if ($musttran) DBcommit();
        unset($stmt);
        
        return $lastinsert;
    }

    function DBAutoInsert($tablename, array $values = null)
    {
        $f = array();
        $v = array();
        $p = array();
        $index = 1;
        foreach($values as $key => $value)
        {
            if (StartsWith($value, 'mysql:'))
            {
                $f[] = "$key";
                $v[] = substr($value, strlen('mysql:'));
            }
            else
            {
                $f[] = "$key";
                $pname = ":p$index";
                $v[] = $pname;
                $p[$pname] = $value;
            }
            
            $index++;
        }
        
        $f = implode(', ', $f);
        $v = implode(', ', $v);
        $query = "INSERT INTO $tablename ($f) VALUES ($v)";
        
        return DBStatement($query, $p);
    }
?>
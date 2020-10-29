<?
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header('Content-Type: application/json; charset=utf-8');

    include_once('config.inc');
    include_once('common.inc');
    
    include_once('Route.php');
    
    function SendError($error, $httpcode = 500)
    {
        header($_SERVER['SERVER_PROTOCOL'] . " $httpcode $error", true, $httpcode);
        echo json_encode(["error" => $error]);
    }
    
    function GetRequestIp()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP']))
            return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        return $_SERVER['REMOTE_ADDR'];
    }
    
    function ExecCPanel($module, $func, $params)
    {
        $cphost = CPANELHOST;
        $cpport = CPANELPORT;
        $cpuser = CPANELUSER;
        $cptoken = CPANELTOKEN;
    
        $query = "https://$cphost:$cpport/json-api/cpanel?cpanel_jsonapi_version=2&cpanel_jsonapi_module=$module&cpanel_jsonapi_func=$func";
        foreach($params as $p => $v)
            $query .= "&$p=$v";
    
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
     
        $header[0] = "Authorization: cpanel $cpuser:$cptoken";
        curl_setopt($curl,CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_URL, $query);
     
        $result = curl_exec($curl);
        curl_close($curl);

        $result = json_decode($result);
        if ($result === NULL)
            throw new Exception('Cannot parse CP result');

        $result = $result->cpanelresult;
        if (!$result)
            throw new Exception('Invalid CP header');

        if ($result->error)
            throw new Exception("CP error: $result->error");

        if (!$result->data || !count($result->data) || !$result->data[0])
            throw new Exception('Invalid CP data');

        return $result->data[0];
    }
    
    Route::add('/', function() { echo json_encode(["name" => ROOTDOMAIN . ' DynDNS service']); });

    Route::add('/update', function()
    {
        if (!$token = $_GET['token'])
            return SendError('TOKEN mandatory', 500);
            
        if (!$ip = GetRequestIp())
            return SendError('Cannot detect IP', 500);
            
        try
        {
            include_once('db.inc');
            
            if (!$settlements = DBSelect('SELECT s.Id, s.SubDomain FROM Settlement s WHERE s.Token = :token', [':token' => $token]))
                return SendError('TOKEN invalid', 403);
                
            $settlement = $settlements[0];
            if (!$subdomain = $settlement->SubDomain)
                return SendError('Subdomain not set for this account', 403);
                
            $rootdomain = ROOTDOMAIN;
            $fulldomain = "$subdomain.$rootdomain.";
            
            $zoneinfos = ExecCPanel('ZoneEdit', 'fetchzone', ['domain' => ROOTDOMAIN]);
                
            $found_address = false;
            $found_line = false;
            foreach($zoneinfos->record as $record)
                if ($record->type == 'A' && $record->class == 'IN' && $record->name == $fulldomain)
                {
                    $found_address = $record->address;
                    $found_line = $record->line;
                }
                
            $updated = false;
            if (!$found_address)
            {
                $cpresult = ExecCPanel('ZoneEdit', 'add_zone_record', ['domain' => ROOTDOMAIN, 'type' => 'A', 'name' => $subdomain, 'address' => $ip, 'ttl' => 3600]);
                if ($statusmsg = trim($cpresult->result->statusmsg))
                    throw new Exception("Cannot execute zone add: $statusmsg");
                $updated = true;
            }
            else
                if ($found_address != $ip)
                {
                    $cpresult = ExecCPanel('ZoneEdit', 'edit_zone_record', ['line' => $found_line, 'domain' => ROOTDOMAIN, 'type' => 'A', 'name' => $subdomain, 'address' => $ip, 'ttl' => 3600]);
                    if ($statusmsg = trim($cpresult->result->statusmsg))
                        throw new Exception("Cannot execute zone edit: $statusmsg");
                    $updated = true;
                }
            
            DBAutoInsert('DynUpdate', ['Settlement' => $settlement->Id, 'IP' => $ip, 'Updated' => $updated ? 1 : 0]);
            
            echo json_encode(['status' => 'success', 'operation' => $updated ? "IP address updated to $ip" : "No updates needed IP stayed $ip"]);
        }
        catch(Exception $ex)
        {
            error_log($ex);
            return SendError($ex->getMessage(), 500);
        }
    }, 'get');

    Route::add('/(.*)', function($var) { SendError("Cannot find resource: $var"); });

    Route::run('/');
?>
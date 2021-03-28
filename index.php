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
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
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
    
    function MSTTS($text)
    {
        $region = TTS_REGION;
        $key = TTS_KEY;

        $query = "https://$region.tts.speech.microsoft.com/cognitiveservices/v1";
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
     
        $headers= [
          "Ocp-Apim-Subscription-Key: $key",
          "Content-Type: application/ssml+xml",
          "X-Microsoft-OutputFormat: audio-16khz-128kbitrate-mono-mp3",
          "User-Agent: curl",
        ];
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_URL, $query);
        
        $data = "<speak version='1.0' xml:lang='hu-HU'><voice name='hu-HU-Szabolcs'>$text</voice></speak>";
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
     
        $result = curl_exec($curl);
        curl_close($curl);
        
        return $result;
    }
    
    Route::add('/', function() { echo json_encode(["name" => ROOTDOMAIN . ' Brain Services']); });

    Route::add('/tts', function()
    {
        if (!$token = $_POST['token'])
            return SendError('TOKEN mandatory', 500);
            
        if (!$text = $_POST['text'])
            return SendError('Text mandatory', 500);
        
        $voice = MSTTS($text);
        header('Content-Type: application/mp3');
        header('Content-Disposition: attachment; filename=voice.mp3');
        echo $voice;
    }, 'post');

/*
    Route::add('/DUMMMY/tts', function()
    {
        if (!$text = $_GET['text'])
            return SendError('Text mandatory', 500);
        
        $voice = MSTTS($text);
        header('Content-Type: application/mp3');
        header('Content-Disposition: attachment; filename=voice.mp3');
        echo $voice;
    }, 'get');
*/

    Route::add('/checktoken', function()
    {
        if (!$token = ($_POST['token'] ?? $_GET['token']))
            return SendError('TOKEN mandatory', 500);
            
        try
        {
            include_once('db.inc');
            
            if (!$settlements = DBSelect('SELECT s.Name, s.SubDomain FROM Settlement s WHERE s.Token = :token', [':token' => $token]))
                return SendError('TOKEN invalid', 403);
                
            $settlement = $settlements[0];
            
            echo json_encode(['status' => 'success', 'name' => $settlement->Name, 'subdomain' => $settlement->SubDomain, 'domain' => $settlement->SubDomain ? ($settlement->SubDomain . '.' . ROOTDOMAIN) : '']);
        }
        catch(Exception $ex)
        {
            error_log($ex);
            return SendError($ex->getMessage(), 500);
        }
    }, 'get+post');

    Route::add('/checkdyndnsremote', function()
    {
        if (!$token = $_POST['token'])
            return SendError('TOKEN mandatory', 500);
            
        try
        {
            include_once('db.inc');
            
            if (!$settlements = DBSelect('SELECT s.Name, s.SubDomain FROM Settlement s WHERE s.Token = :token', [':token' => $token]))
                return SendError('TOKEN invalid', 403);
                
            $settlement = $settlements[0];
            
            if (!$domain = $settlement->SubDomain ? ('https://' . $settlement->SubDomain . '.' . ROOTDOMAIN) : '')
            {
                echo json_encode(['status' => 'error', 'text' => 'DOMAIN not assigned']);
                return;
            }

            $starttime = microtime(true);
            $content = file_get_contents($domain);
            if ($content === false)
            {
                echo json_encode(['status' => 'error', 'text' => "Cannot access $domain"]);
                return;
            }
            $endtime = microtime(true);
            
            echo json_encode(['status' => 'success', 'domain' => $domain, 'time' => round(1000 * ($endtime - $starttime))]);
        }
        catch(Exception $ex)
        {
            error_log($ex);
            return SendError($ex->getMessage(), 500);
        }
    }, 'post');

    Route::add('/storebackup', function()
    {
        if (!$token = $_POST['token'])
            return SendError('TOKEN mandatory', 500);
            
        try
        {
            include_once('db.inc');
            
            if (!$settlements = DBSelect('SELECT s.Id, s.Code FROM Settlement s WHERE s.Token = :token', [':token' => $token]))
                return SendError('TOKEN invalid', 403);
                
            $settlement = $settlements[0];

            if (!$_FILES["backupfile"])
                return SendError('No upload file', 500);
                
            $tmp_name = $_FILES["backupfile"]["tmp_name"];
            if (!file_exists($tmp_name))
                return SendError('Not existed upload file', 500);
            if (!$filesize = filesize($tmp_name))
                return SendError('Zero size upload file', 500);
            
            $name = $_FILES["backupfile"]["name"];
            
            $folderdate = "../backup/" .date("Ymd");
            if (!file_exists($folderdate))
                if (!mkdir($folderdate))
                    return SendError('Cannot create date directory', 500);
            $folder = "../backup/" .date("Ymd") . "/" . $settlement->Code;
            if (!file_exists($folder))
                if (!mkdir($folder))
                    return SendError('Cannot create directory', 500);
            move_uploaded_file($tmp_name, "$folder/$name");
            
            DBAutoInsert('Backup', ['Settlement' => $settlement->Id, 'Size' => $filesize]);
            
            echo json_encode(['status' => 'success', 'filesize' => $filesize]);
        }
        catch(Exception $ex)
        {
            error_log($ex);
            return SendError($ex->getMessage(), 500);
        }
    }, 'post');

    Route::add('/dynupdate', function()
    {
        if (!$token = $_POST['token'])
            return SendError('TOKEN mandatory', 500);
            
        if (!$ip = GetRequestIp())
            return SendError('Cannot detect IP', 500);
            
        try
        {
            include_once('db.inc');
            
            if (!$settlements = DBSelect('SELECT s.Id, s.SubDomain, s.IP FROM Settlement s WHERE s.Token = :token', [':token' => $token]))
                return SendError('TOKEN invalid', 403);
                
            $settlement = $settlements[0];
            if (!$subdomain = $settlement->SubDomain)
                return SendError('Subdomain not set for this account', 403);
                
            $updated = false;
            if ($settlement->IP != $ip)
            {
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
            }
            DBAutoInsert('DynUpdate', ['Settlement' => $settlement->Id, 'IP' => $ip, 'Updated' => $updated ? 1 : 0]);
            if ($updated)
                DBAutoUpdate('Settlement', ['IP' => $ip], ['Id' => $settlement->Id]);
            echo json_encode(['status' => 'success', 'updated' => $updated ? 'true' : 'false', 'operation' => $updated ? "IP address updated to $ip" : "No updates needed IP still $ip"]);
        }
        catch(Exception $ex)
        {
            error_log($ex);
            return SendError($ex->getMessage(), 500);
        }
    }, 'post');

    Route::add('/(.*)', function($var) { SendError("Cannot find resource: $var"); });

    Route::run('/');
?>
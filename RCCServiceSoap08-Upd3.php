<?php

// this defines the rccservicesoap class yeah so uhm use it if you want to :)
// not needing any other file, only the class then you are ready to go
// originally made by nolanwhy

/*
    -------------------- Update 3 - 05/06/2026 ---------------------
	# minor fixes
    # proper sanitization
    # improved guide on how to use rccsoap08
    # fix if $script contains <, >, or & it might break the XML
	# better readme file
    -------------------------------------------------------------
*/

// HOW TO USE
/*
require_once("your/path/here/RCCServiceSoap08.php");

$RCCServiceSoap = new RCCServiceSoap08("127.0.0.1", 64989, "roblox.com", true);
// PARAMETERS:
// "127.0.0.1")"  			= rcc ip
// 64989                  	= rcc port
// "roblox.com"				= patched site domain
// true                     = fix renders

$result = $RCCServiceSoap->execScript('print("Hello World")', "job1", 5);
echo $result;
// PARAMETERS:
// "print('Hello World')     = Lua script to execute
// "job1"                    = job id (must be unique per request)
// 1                         = job expiration time (seconds)


// QUICK TEST FUNCTION
echo $RCCServiceSoap->helloWorld();
*/

class RCCServiceSoap08
{
    public $ip;
    public $port;
    public $url;
    public $renderFix;

    public function __construct($ip = "127.0.0.1", $port = 64989, $url = "roblox.com", $renderFix = true)
    {
		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			throw new InvalidArgumentException("invalid IP");
		}

        if (!is_int($port) || $port < 1) {
            throw new InvalidArgumentException("invalid RCC port");
        }

        if (!filter_var('http://' . $url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("invalid URL");
        }

        $this->ip = $ip;
        $this->port = $port;
        $this->url = $url;
        $this->renderFix = $renderFix;
    }

    public function requestUrl($url, $xml)
    {
        $curlHandle = curl_init($url);

        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, ["Content-Type: text/xml"]);
		// rccservice accepts only post requests
        curl_setopt($curlHandle, CURLOPT_POST, true);
        curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $xml);
		
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($curlHandle);

        $result = str_replace(
            [
                "<ns1:value>",
                "</ns1:value>",
                "</ns1:OpenJobResult>",
                "<ns1:OpenJobResult>",
                "<ns1:type>",
                "</ns1:type>",
                "<ns1:table>",
                "</ns1:table>",
                "</ns1:OpenJobResult>",
                "</ns1:OpenJobResponse>",
                "</SOAP-ENV:Body>",
                "</SOAP-ENV:Envelope>"
            ],
            "",
            strstr(
                str_replace(
                    [
                        "LUA_TSTRING",
                        "LUA_TNUMBER",
                        "LUA_TBOOLEAN",
                        "LUA_TTABLE"
                    ],
                    "",
                    $response
                ),
                "<ns1:value>"
            )
        );

        // trim trailing render data returned by some RCC builds.
        if ($this->renderFix) {
            $luaValuePosition = strpos($result, "<ns1:LuaValue>");

            if ($luaValuePosition !== false) {
                $result = substr($result, 0, $luaValuePosition);
            }
        }

        return $result;
    }

    public function execScript($script = 'print("Hello World!")', $jobId = "helloworld", $jobExpiration = 0.1)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:ns1="http://' . $this->url . '/" xmlns:ns2="http://' . $this->url . '/RCCServiceSoap" xmlns:ns3="http://' . $this->url . '/RCCServiceSoap12">
            <SOAP-ENV:Body>
                <ns1:OpenJob>
                    <ns1:job>
                        <ns1:id>' . htmlspecialchars($jobId, ENT_XML1, 'UTF-8', false) . '</ns1:id>
                        <ns1:expirationInSeconds>' . htmlspecialchars((string) $jobExpiration, ENT_XML1, 'UTF-8', false) . '</ns1:expirationInSeconds>
                        <ns1:category>1</ns1:category>
                        <ns1:cores>321</ns1:cores>
                    </ns1:job>
                    <ns1:script>
                        <ns1:name>Script</ns1:name>
                        <ns1:script>
                            ' . htmlspecialchars($script, ENT_XML1, 'UTF-8', false) . '
                        </ns1:script>
                    </ns1:script>
                </ns1:OpenJob>
            </SOAP-ENV:Body>
        </SOAP-ENV:Envelope>';

        return $this->requestUrl(
            "http://" . $this->ip . ":" . $this->port,
            $xml
        );
    }

    public function helloWorld()
    {
        return $this->execScript(
            'print("Hello World!")',
            "helloworld",
            0.1
        );
    }
}

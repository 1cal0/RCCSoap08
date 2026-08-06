<?php

/*
    - 06/08/2026
	# minor fixes
    # proper sanitization
    # improved guide on how to use rccsoap08
    # fix if $script contains <, >, or & it might break the XML
	# better readme file
    -------------------------------------------------------------
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

        $port = (int)$port;
        if ($port < 1) {
            throw new InvalidArgumentException("invalid port");
        }

        if (!filter_var('http://' . $url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("invalid URL");
        }

        $this->ip = $ip;
        $this->port = $port;
        $this->url = $url;
        $this->renderFix = $renderFix;
    }

    public function requestUrl($url, $xml, $curlTimeout = 30)
    {
        $curlHandle = curl_init($url);

        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, ["Content-Type: text/xml"]);
        // rccservice accepts only POST requests
        curl_setopt($curlHandle, CURLOPT_POST, true);
        curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $xml);
		
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, $curlTimeout);

        $response = curl_exec($curlHandle);

        if ($response === false) {
            curl_close($curlHandle);
            return '';
        }

        curl_close($curlHandle);

        // strip LUA type labels
        $cleaned = str_replace(
            ["LUA_TSTRING", "LUA_TNUMBER", "LUA_TBOOLEAN", "LUA_TTABLE"],
            "",
            $response
        );

        // extract the value block using regex (more robust than strstr/str_replace chain)
        if (preg_match('/<ns1:value>(.*)<\/ns1:value>/s', $cleaned, $m)) {
            $result = $m[1];
        } elseif (strstr($cleaned, "<ns1:value>")) {
            // fallback: strip everything before and after the value block
            $result = strstr($cleaned, "<ns1:value>");
            $result = str_replace(
                [
                    "<ns1:value>",
                    "</ns1:value>",
                    "</ns1:OpenJobResult>",
                    "<ns1:OpenJobResult>",
                    "</ns1:OpenJobResponse>",
                    "</SOAP-ENV:Body>",
                    "</SOAP-ENV:Envelope>"
                ],
                "",
                $result
            );
        } else {
            $result = $cleaned;
        }

        // trim trailing render data returned by some RCC builds.
        if ($this->renderFix) {
            $luaValuePosition = strpos($result, "<ns1:LuaValue>");
            if ($luaValuePosition !== false) {
                $result = substr($result, 0, $luaValuePosition);
            }
        }

        return trim($result);
    }

    public function execScript($script = 'print("Hello World!")', $jobId = "helloworld", $jobExpiration = 0.1)
    {
        $curlTimeout = max((int)$jobExpiration + 10, 15);

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
            $xml,
            $curlTimeout
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

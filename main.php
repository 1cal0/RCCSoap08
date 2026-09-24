<?php

/*
    - 09/24/2026
    # fixed: $url was still interpolated into the XML unescaped (the
      06/08 fix covered $script/$jobId/$jobExpiration but missed this one
      - same bug class, one field short)
    # requestUrl() now throws on curl failure / non-2xx HTTP / SOAP
      fault instead of returning '', which was indistinguishable from a
      script that legitimately printed nothing
    # decode XML entities in the returned value - the server escapes
      special characters in its response (as any well-formed XML server
      would), we were never decoding them back, so script output
      containing &, <, > came back to callers still escaped
    # value-block regex is now non-greedy
    # curl handle is now reused across calls instead of reconnecting
      (new socket + handshake) on every single request
    # dropped SSL_VERIFYHOST/VERIFYPEER=false - currently dead code
      since the endpoint is always requested over plain http, and a
      latent MITM hole if that ever changes to https
    # constructor properties are now promoted + readonly (PHP 8.1+)

*/

class RCCSoap08
{
    /** @var ?CurlHandle Reused across calls so repeated requests to the same RCC instance don't pay for a fresh TCP handshake every time. */
    private ?CurlHandle $curlHandle = null;

    /**
     * @throws InvalidArgumentException if $ip, $port, or $url is invalid.
     */
    public function __construct(
        public readonly string $ip = "127.0.0.1",
        public readonly int $port = 64989,
        public readonly string $url = "roblox.com",
        public readonly bool $renderFix = true
    ) {
        if (!filter_var($this->ip, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException("invalid IP");
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException("invalid port");
        }

        if (!filter_var('http://' . $this->url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("invalid URL");
        }
    }

    public function __destruct()
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
        }
    }

    /**
     * Sends a raw SOAP request to the RCC service and returns the decoded
     * <ns1:value> payload from a successful response.
     *
     * @throws RuntimeException on a transport failure, a non-2xx HTTP
     *         status, or a SOAP fault returned by the service.
     */
    public function requestUrl(string $url, string $xml, int $curlTimeout = 30): string
    {
        $curlHandle = $this->getCurlHandle();

        curl_setopt_array($curlHandle, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => ["Content-Type: text/xml"],
            // rccservice accepts only POST requests
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $curlTimeout,
            // Fail fast on a dead/unreachable host instead of burning the
            // whole request timeout just trying to connect.
            CURLOPT_CONNECTTIMEOUT => min(10, $curlTimeout),
            // Small request/response either way - skip Nagle's delay.
            CURLOPT_TCP_NODELAY => true,
        ]);

        $response = curl_exec($curlHandle);

        if ($response === false) {
            throw new RuntimeException("RCC request failed: " . curl_error($curlHandle));
        }

        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("RCC request returned HTTP {$httpCode}");
        }

        if (preg_match('/<SOAP-ENV:Fault>(.*?)<\/SOAP-ENV:Fault>/s', $response, $faultMatch)) {
            $faultString = 'unknown SOAP fault';
            if (preg_match('/<faultstring[^>]*>(.*?)<\/faultstring>/s', $faultMatch[1], $faultStringMatch)) {
                $faultString = html_entity_decode(trim($faultStringMatch[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            throw new RuntimeException("RCC SOAP fault: " . $faultString);
        }

        // strip LUA type labels
        $cleaned = str_replace(
            ["LUA_TSTRING", "LUA_TNUMBER", "LUA_TBOOLEAN", "LUA_TTABLE"],
            "",
            $response
        );

        // Extract the value block. Non-greedy (.*?) so this stops at the
        // FIRST closing tag rather than swallowing everything up to the
        // last one if the response ever contains more than one
        // <ns1:value> block.
        if (preg_match('/<ns1:value>(.*?)<\/ns1:value>/s', $cleaned, $matches)) {
            $result = $matches[1];
        } elseif (str_contains($cleaned, "<ns1:value>")) {
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

        // The server XML-escapes the value it embeds (same reason we
        // escape $script/$jobId on the way in) - decode it back so
        // callers get the Lua script's actual output instead of literal
        // &amp;/&lt;/&gt; when that output contains special characters.
        return html_entity_decode(trim($result), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Opens an RCC job that runs the given Lua script.
     *
     * @throws RuntimeException on a transport failure, non-2xx HTTP
     *         status, or a SOAP fault (see requestUrl()).
     */
    public function execScript(
        string $script = 'print("Hello World!")',
        string $jobId = "HelloWorld",
        float $jobExpiration = 0.1
    ): string {
        $curlTimeout = max((int) $jobExpiration + 10, 15);

        // xmlns attribute values need quotes escaped too (unlike the
        // element-text fields below, which only need <, >, & handled) -
        // this sits inside a double-quoted XML attribute, so a literal "
        // here would break out of it. ENT_XML1 alone does NOT escape
        // quotes; ENT_QUOTES is what adds that.
        $escapedUrl = htmlspecialchars($this->url, ENT_QUOTES | ENT_XML1, 'UTF-8', false);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>
        <SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:ns1="http://' . $escapedUrl . '/" xmlns:ns2="http://' . $escapedUrl . '/RCCServiceSoap" xmlns:ns3="http://' . $escapedUrl . '/RCCServiceSoap12">
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

    public function helloWorld(): string
    {
        return $this->execScript(
            'print("Hello World!")',
            "helloworld",
            0.1
        );
    }

    private function getCurlHandle(): CurlHandle
    {
        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
        }

        return $this->curlHandle;
    }
}

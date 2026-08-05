# RCCSoap08 (improved version of RCCServiceSoap08)

This project uses **RCCService 0.3.783.0** (build from **May 14, 2008**).

This project was originally made by nolanwhy.

## Installation

1. Download and install RCCService:
   https://archive.org/download/rbxgssetup/RBXGSSetup_0.3.783.0.msi

2. Open Command Prompt and navigate to the installation folder:

   ```cmd
   cd "C:\Program Files (x86)\ROBLOX Corporation\RCCService"
   ```

3. Start RCCService:

   ```cmd
   RCCService.exe -console -start -verbose -placeid:1818
   ```

If everything is set up correctly, RCCService will start and display verbose output in the console.


# Using RCCSoap08

Include the library:

```php
require_once("path/to/RCCServiceSoap08.php");
```

Create an RCCSoap08 instance:

```php
$RCCServiceSoap = new RCCServiceSoap08(
    "127.0.0.1",   // RCCService IP
    64989,         // RCCService port
    "roblox.com",  // Patched site domain
    true           // Fix renders
);
```

## Execute a Lua Script

```php
$result = $RCCServiceSoap->execScript(
    'print("Hello World")',
    "job1", // Unique job ID
    5       // Job expiration (seconds)
);

echo $result;
```

> **Note:** Each request should use a unique job ID.

## Quick Test

Use the built-in test function to verify the connection:

```php
echo $RCCServiceSoap->helloWorld();
```

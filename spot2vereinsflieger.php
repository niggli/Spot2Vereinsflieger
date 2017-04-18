<?php 

    // Gateway Spot to Vereinsflieger.de
    //
	// This script connects to the Spot satellite messenger XML API and generates flight log entrys in vereinsflieger.de
	// when a takeoff or landing is detected. After sucessful entry of a flight, a Pushover notification is sent. 
	// Messages from Spot are interpreted as follows:
	// - Message "OK": Landing
	// - Message "CUSTOM": Takeoff
	//
    // Versionen
    // 1.0 - 18.04.2017 First draft

	// Enable error output
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);
	
	// global variables, to be replaced in future by config file
	$vereinsfliegerLogin = "";
	$vereinsfliegerPassword = "";
	$flightAirport = "";
	$flightPilotName = "";
	$flightPilotId = "";
	$flightCallsign = "";
	$flightStarttype = "";
	$pushoverApplicationKey = "";
	$pushoverUserKey = "";
	$spotFeedID = "";
	
	require_once('VereinsfliegerRestInterface.php');
	date_default_timezone_set ( "UTC");
	
	// Generate adress of todays XML
	$now = time();
	$startDate = date("Y-m-dT00:00:00-0000", $now);
	$endDate = date("Y-m-dT23:59:59-0000", $now);
	$xmlurl = "https://api.findmespot.com/spot-main-web/consumer/rest-api/2.0/public/feed/"
			. $spotFeedID
			. "/message.xml"
			. "?startDate=" . $startDate
			. "&endDate=" . $endDate;
			
	$xml = simplexml_load_file($xmlurl);

	// for test load locally
	//$xml = simplexml_load_file("test.xml");
	
	$messagecount = $xml->feedMessageResponse->count;
	
	if ($messagecount == null)
	{
		$messagecount = 0;
	}

	//loop from oldest to newest
	for ($counter=($messagecount-1); $counter>=0; $counter--)
    {
    	$messagetype = $xml->feedMessageResponse->messages->message[$counter]->messageType;
		$uniqueid = $xml->feedMessageResponse->messages->message[$counter]->id;
	
		// Load all known unique IDs in an array
		$lines = file("knownSpotMessageIDs.txt", FILE_IGNORE_NEW_LINES);
		
		// Check if file has been loaded
		if ($lines != false)
		{
			if (!in_array($uniqueid, $lines))
			{
				if ($messagetype == "OK")
				{
					// Landing message
					$unixtime = (int) $xml->feedMessageResponse->messages->message[$counter]->unixTime;
					
					//if (starttime exists and is same day)
					if (true)
					{
						//get current flight ID
						$flightidfile = fopen("current_flight_id.txt", "r");
						$flightid = fgets($flightidfile);
						fclose($flightidfile);
						
						// Add landing time to existing flight
						$result = storeLandingTime($flightid, $unixtime);
						
						if ($result > 0)
						{
							$message = "Success storing landing time, battery is: " . $xml->feedMessageResponse->messages->message[$counter]->batteryState;
							// Send notification
							sendNotification($message, $pushoverUserKey);
							echo "$message <br />";
						} else
						{
							echo "Fehler: $result";
						}
						
					} else
					{
						// Send error notification
						echo "Notification: No matching takeoff found. <br />";				
					}
					
					// Clear current flight ID
					$flightidfile = fopen("current_flight_id.txt", "w");
					fwrite($flightidfile, "");
					fclose($flightidfile);
					
					// Save Spot unique ID
					$uniqueidfile = fopen("knownSpotMessageIDs.txt", "a");
					fwrite($uniqueidfile, "$uniqueid\n");
					fclose($uniqueidfile);
					
				} else if ($messagetype == "CUSTOM")
				{
					// Takeoff message
					$unixtime = (int) $xml->feedMessageResponse->messages->message[$counter]->unixTime;
					
					$result = storeStartTime($unixtime);
					
					if ($result > 0)
					{
						// Save vereinsflieger.de flight ID
						$flightidfile = fopen("current_flight_id.txt", "w");
						fwrite($flightidfile, $result);
						fclose($flightidfile);
						
						// Send notification
						$message = "Success storing takeoff time, battery is: " . $xml->feedMessageResponse->messages->message[$counter]->batteryState;
						sendNotification($message, $pushoverUserKey);
						echo "$message <br />";
					} else
					{
						echo "Fehler: $result";
					}
					
					// Save Spot unique ID
					$uniqueidfile = fopen("knownSpotMessageIDs.txt", "a");
					fwrite($uniqueidfile, "$uniqueid\n");
					fclose($uniqueidfile);
					
				} else
				{
					// Wether takeoff nor landing message, ignore
				}		
			}
		} else
		{
			echo "Fehler: File knownSpotMessageIDs.txt not found or empty";	
		}
	
	
	}


	function sendNotification($message, $userkey)
	{
		global $pushoverApplicationKey;
		
		curl_setopt_array($ch = curl_init(), array(
  			CURLOPT_URL => "https://api.pushover.net/1/messages.json",
  			CURLOPT_POSTFIELDS => array(
    		"token" => $pushoverApplicationKey,
    		"user" => $userkey,
    		"message" => $message,
  			),
  			CURLOPT_SAFE_UPLOAD => true,
		));
		curl_exec($ch);
		curl_close($ch);

	}

    function storeStartTime ($unixtime)
    {
    	global $vereinsfliegerLogin;
    	global $vereinsfliegerPassword;  
		global $flightAirport;
		global $flightPilotName;
		global $flightPilotId;
		global $flightCallsign;
		global $flightStarttype;

        $a = new VereinsfliegerRestInterface();
        $result = $a->SignIn($vereinsfliegerLogin, $vereinsfliegerPassword,0);

		if ($result) {

            // convert timestamp to correct format
            $takeofftime = date("Y-m-d H:i", $unixtime);
            
            // build flight data from constant strings
            $Flight = array(
                'callsign' => $flightCallsign, 
                'pilotname' => $flightPilotName, 
                'uidpilot' => $flightPilotId,
                'starttype' => $flightStarttype,
                'departuretime' => $takeofftime,
                'departurelocation' => $flightAirport);
                //'departurelocation' => "Grenchen",
                //'towcallsign' => "HB-EQM");

            // insert flight into DB
            $result = $a->InsertFlight ($Flight);
            if ($result) {
              
                // get flight ID which is needed for changing it later
                $aResponse = $a->GetResponse();
                $flightid = $aResponse["flid"];
				
				return $flightid;

            } else {
                return -1;
            }
            
        }
        else {
			return -2;
        }
    }

    function storeLandingTime ($flightid, $unixtime)
    {
		global $vereinsfliegerLogin;
    	global $vereinsfliegerPassword;  
		global $flightAirport;

 		// get timestamp in the correct format
      	$timestamp = date("Y-m-d H:i", $unixtime);
            
      	// add landing time
      	$Flight = array(
        	'arrivaltime' => $timestamp,
        	'arrivallocation' => $flightAirport);
        	//'arrivallocation' => "Grenchen");
		
	  	$a = new VereinsfliegerRestInterface();
      	$result = $a->SignIn($vereinsfliegerLogin,$vereinsfliegerPassword,0);
		
      	if ($result)
		  {
	      	$result = $a->UpdateFlight($flightid, $Flight);
	      
	      	if ($result)
	      	{
				return $flightid;
	      	} else
	      	{
				return -1;
	      	}
		  } else
		  {
			return -2;
		  }
	
	    }




?>

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
  // 1.1 - 14.06.2017 Use start time instead of flight ID, because flight ID is sometimes manually changed in the field.
  // 1.2 - 18.01.2018 Add support for localtime in vereinsflieger.de. Some cleanups.
  // 1.3 - 07.05.2018 Adapt to some new behaviour of the vereinsflieger.de API. Only write landing time if empty before.
  // 1.4 - 14.03.2019 Automatic airport detection. Don't create flight if it's is already in vereinsflieger.
  // 1.5 - 18.03.2019 Bugfix airport detection.
  // 1.6 - 11.06.2019 Add vereinsflieger appkey to SignIn

  
  // Enable error output
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);
  
  // Prepare array for airport list
  $airportentry = array (
    "name" => "",
    "timezone" => "",
    "towcallsign" => "",
    "flighttypeID" => "",
    "flightChargeMode" => "",
    "latitude" => "",
    "longitude" => ""
  );
  $airports = array();

  $configuration = parse_ini_file ("spot2vereinsflieger.cfg.php", 1);
  $spotFeedID = $configuration["spot"]["feedID"];
  $vereinsfliegerLogin = $configuration["vereinsflieger"]["login_name"];
  $vereinsfliegerPassword = $configuration["vereinsflieger"]["password"];
  $vereinsfliegerAppkey = $configuration["vereinsflieger"]["appkey"];
  $flightAirport = $configuration["defaultairport"]["name"];
  $flightTimezone = $configuration["defaultairport"]["timezone"];
  $flightPilotName = $configuration["flightdata"]["pilotname"];
  $flightPilotId = $configuration["flightdata"]["pilotId"];
  $flightCallsign = $configuration["flightdata"]["callsign"];
  $flightStarttype = $configuration["flightdata"]["starttype"];
  $flightTowCallsign = $configuration["defaultairport"]["towCallsign"];
  $flightTypeID = $configuration["defaultairport"]["flightTypeID"];
  $flightChargeMode = $configuration["defaultairport"]["flightChargeMode"];
  $pushoverApplicationKey = $configuration["pushover"]["applicationKey"];
  $pushoverUserKey = $configuration["pushover"]["userKey"];
  $airportNames = explode (",",$configuration["airports"]["names"]);
  $airportTimezones = explode (",",$configuration["airports"]["timezones"]);
  $airportTowCallsign = explode (",",$configuration["airports"]["towCallsign"]);
  $airportFlightTypeID = explode (",",$configuration["airports"]["flightTypeID"]);
  $airportFlightChargeMode = explode (",",$configuration["airports"]["flightChargeMode"]);
  $airportLatitudes = explode (",",$configuration["airports"]["latitudes"]);
  $airportLongitudes = explode (",",$configuration["airports"]["longitudes"]);

  for ($i = 0; $i < count($airportNames); $i++)
  {
    $airports[$i] = $airportentry;
    $airports[$i]["name"] = $airportNames[$i];
    $airports[$i]["timezone"] = $airportTimezones[$i];
    $airports[$i]["towcallsign"] = $airportTowCallsign[$i];
    $airports[$i]["flighttypeID"] = $airportFlightTypeID[$i];
    $airports[$i]["flightChargeMode"] = $airportFlightChargeMode[$i];
    $airports[$i]["latitude"] = $airportLatitudes[$i];
    $airports[$i]["longitude"] = $airportLongitudes[$i];
  }
  
  require_once('VereinsfliegerRestInterface.php');
  date_default_timezone_set ("UTC");
  
  // Generate adress of todays XML
  $now = time();
  $startDate = date("Y-m-d\T00:00:00-0000", $now);
  $endDate = date("Y-m-d\T23:59:59-0000", $now);
  $xmlurl = "https://api.findmespot.com/spot-main-web/consumer/rest-api/2.0/public/feed/"
            . $spotFeedID
            . "/message.xml"
            . "?startDate=" . $startDate
            . "&endDate=" . $endDate;
  
  echo "Loading XML from URL: " . $xmlurl . "<br />";
  $xml = simplexml_load_file($xmlurl);
  
  // for test load locally
  //$xml = simplexml_load_file("test.xml");
  
  $messagecount = $xml->feedMessageResponse->count;
  
  if ($messagecount == null)
  {
    echo "No messages <br />";
    $messagecount = 0;
  }

  //loop from oldest to newest
  echo "Looking for relevant and new messages <br />";
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
          echo "Landing message found <br />";
          $unixtime = (int) $xml->feedMessageResponse->messages->message[$counter]->unixTime;
          
          //get starttime
          $starttimefile = fopen("starttime.txt", "r");
          $starttime = fgets($starttimefile);
          fclose($starttimefile);
          
          //check if starttime is the same date
          $starttimeobject = (new DateTime())->setTimestamp($starttime);
          $startDatestring = $starttimeobject->format("Y-m-d");
          $landingtimeobject = (new DateTime())->setTimestamp($unixtime);
          $landingDatestring = $landingtimeobject->format("Y-m-d");
          
          // Find out at which airport the landing was
          $latitude = (float) $xml->feedMessageResponse->messages->message[$counter]->latitude;
          $longitude = (float) $xml->feedMessageResponse->messages->message[$counter]->longitude;
          $airportindex = findAirport($latitude, $longitude);
          if ($airportindex > 0)
          {
            $flightAirport = $airports[$airportindex]["name"];
            echo "Flight ends at airport: " . $flightAirport . " <br />";
          } else
          {
            echo "Error finding landing airport <br />";
          }
          
          if ($startDatestring === $landingDatestring)
          {
            //search for flight with pilot name and starttime
            $flightid = findFlightID($starttimeobject, $flightPilotName);
            
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
              echo "Error storing landing time: $result";
            }
            
            
          } else
          {
            // Send error notification
            echo "Error: No matching takeoff found. <br />";        
          }
          
          // Clear start time
          $starttimefile = fopen("starttime.txt", "w");
          fwrite($starttimefile, "");
          fclose($starttimefile);
          
          // Save Spot unique ID
          $uniqueidfile = fopen("knownSpotMessageIDs.txt", "a");
          fwrite($uniqueidfile, "$uniqueid\n");
          fclose($uniqueidfile);
          
        } else if ($messagetype == "CUSTOM")
        {
          // Takeoff message
          echo "Takeoff message found <br />";
          $unixtime = (int) $xml->feedMessageResponse->messages->message[$counter]->unixTime;
          
          // Only do something if flight is not yet in Vereinsflieger
          if (findFlightID((new DateTime())->setTimestamp($unixtime), $flightPilotName) < 0)
          {    
            // Find out at which airport the takeoff was
            $latitude = (float) $xml->feedMessageResponse->messages->message[$counter]->latitude;
            $longitude = (float) $xml->feedMessageResponse->messages->message[$counter]->longitude;
            $airportindex = findAirport($latitude, $longitude);
            if ($airportindex > 0)
            {
              $flightAirport = $airports[$airportindex]["name"];
              $flightTowCallsign = $airports[$airportindex]["towcallsign"];
              $flightTypeID = $airports[$airportindex]["flighttypeID"];
              $flightChargeMode = $airports[$airportindex]["flightChargeMode"];
              $flightTimezone = $airports[$airportindex]["timezone"];              
              echo "Flight starts at airport: " . $flightAirport . " <br />";
            } else
            {
              echo "Error finding start airport <br />";
            }
            
            $result = storeStartTime($unixtime);
            
            if ($result > 0)
            { 
              // Send notification
              $message = "Success storing takeoff time, battery is: " . $xml->feedMessageResponse->messages->message[$counter]->batteryState;
              sendNotification($message, $pushoverUserKey);
              echo "$message <br />";
            } else
            {
              echo "Error storing starttime: $result";
            }

          } else
          {
            echo "Flight already in Vereinsflieger, don't add it <br />";            
          }
          
          // Save start time in file
          $starttimefile = fopen("starttime.txt", "w");
          fwrite($starttimefile, $unixtime);
          fclose($starttimefile);
          
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
      echo "Error: File knownSpotMessageIDs.txt not found or empty";  
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
    global $vereinsfliegerAppkey;
    global $flightAirport;
    global $flightPilotName;
    global $flightPilotId;
    global $flightCallsign;
    global $flightStarttype;
    global $flightTowCallsign;
    global $flightTypeID;
    global $flightChargeMode;
    global $flightTimezone;
    
    echo "storeStartTime flightChargeMode: " . $flightChargeMode;

    $a = new VereinsfliegerRestInterface();
    $success = $a->SignIn($vereinsfliegerLogin, $vereinsfliegerPassword,0,$vereinsfliegerAppkey);

    if ($success)
    {
      // convert timestamp to correct format
      $takeofftime = date_format(timestamp_to_local($unixtime, $flightTimezone), "Y-m-d H:i");
      
      // build flight data from constant strings
      $Flight = array(
        'callsign' => $flightCallsign, 
        'pilotname' => $flightPilotName, 
        'uidpilot' => $flightPilotId,
        'starttype' => $flightStarttype,
        'ftid' => $flightTypeID,
        'chargemode' => $flightChargeMode,
        'departuretime' => $takeofftime,
        'departurelocation' => $flightAirport,
        'towcallsign' => $flightTowCallsign);

      // insert flight into DB
      $success = $a->InsertFlight ($Flight);
      if ($success)
      {
        // get flight ID which is needed for changing it later
        $aResponse = $a->GetResponse();
        $flightid = $aResponse["flid"];
        
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

  function storeLandingTime ($flightid, $unixtime)
  {
    global $vereinsfliegerLogin;
    global $vereinsfliegerPassword;
    global $vereinsfliegerAppkey;
    global $flightAirport;
    global $flightTimezone;

    // get landing timestamp in the correct format
    $landingtime = date_format(timestamp_to_local($unixtime, $flightTimezone), "Y-m-d H:i");

    $a = new VereinsfliegerRestInterface();
    $success = $a->SignIn($vereinsfliegerLogin,$vereinsfliegerPassword,0,$vereinsfliegerAppkey);
    
    if ($success)
    {
      // read flight for start time. Since May 2018 strange things happen if only landing time is updated.
      $success = $a->GetFlight ($flightid);

      if ($success)
      {
        $aResponse = $a->GetResponse();
        $starttimeobject = new DateTime($aResponse["departuretime"]);
        $starttime = date_format($starttimeobject, "Y-m-d H:i");

        // add landing time
        $Flight = array(
          'arrivaltime' => $landingtime,
          'departuretime' => $starttime,
          'arrivallocation' => $flightAirport);
        
        $updatesuccess = $a->UpdateFlight($flightid, $Flight);
        
        if ($updatesuccess)
        {
          return $flightid;
        } else
        {
          return -1;
        } 
      } else
      {
        return -3;
      }
      
    } else
    {
      return -2;
    }
  }
 
  function findFlightID ($starttime, $pilotname)
  {
    echo "findFlightID()<br />";
    
    global $vereinsfliegerLogin;
    global $vereinsfliegerPassword;
    global $vereinsfliegerAppkey;
    global $flightTimezone;
    
    // login to Vereinsflieger
    $a = new VereinsfliegerRestInterface();
    $success = $a->SignIn($vereinsfliegerLogin, $vereinsfliegerPassword, 0, $vereinsfliegerAppkey);
    
    if ($success)
    {
      echo "success login<br />";  
      
      // Get all flights from date of flight
      $datum = date_format($starttime, "Y-m-d");
      $success = $a->GetFlights_date ($datum);
      if ($success)
      {
        echo "success getting flights<br />";
        $aResponse = $a->GetResponse();
        $no_Flights = count ($aResponse) - 1; // last element is httpresponse...
        if ($no_Flights > 0)
        {
          for ($i = 0; $i < $no_Flights; $i++)
          {
            $daydate = $starttime;
            $starttimeVereinsflieger = timestring_to_utc($aResponse[$i]["departuretime"], $daydate, $flightTimezone);
            $pilotVereinsflieger = $aResponse[$i]["pilotname"];
            $nachname = substr($pilotVereinsflieger, 0, strpos($pilotVereinsflieger, ','));
            $vorname = substr($pilotVereinsflieger, strpos($pilotVereinsflieger, ',') + 2);
            $pilotVereinsflieger = $vorname . " " . $nachname;
            
            echo "Flug von Pilot: " . $pilotVereinsflieger . "<br />";

            if($pilotname === $pilotVereinsflieger)
            {
              echo "pilot found<br />";
              if (abs($starttime->getTimestamp() - $starttimeVereinsflieger->getTimestamp()) < 1800) // 30 minutes
              {
                echo "landezeit: " . $aResponse[$i]["arrivaltime"];
                if ($aResponse[$i]["arrivaltime"] === "00:00:00")
                {               
                  echo "flight found<br />";
                  return $aResponse[$i]["flid"];
                } else
                {
                  echo "Arrival time already set<br />";
                }

              } else
              {
                echo "times not matching<br />";
                echo "starttime: " . $starttime->getTimestamp() . "<br />";
                $temp = $starttimeVereinsflieger->getTimestamp();
                echo "starttime vereinsflieger: " . $temp . "<br />";
              }
            }
          }
          // no flight found
          echo "no flight found<br />";
          return -1;
        } else
        {
          // zero flights today
          echo "zero flights today<br />";
          return -2;
        }
        
      } else
      {
        // error when retrieving flights
        echo "error when retrieving flights<br />";
        return -3;
      }
      
    } else
    {
      // error in logging in to vereinsflieger
      echo "error in logging in to vereinsflieger<br />";
      return -4;
    }
    
  }

  // Converts a UTC unix timestamp to a localtime datetime object
  function timestamp_to_local($timestamp, $timezone)
  {
    // Create date object
    $timestamp_utc = (new DateTime(null, new DateTimeZone("UTC")))->setTimestamp($timestamp);
    
    // Convert to localtime
    $timestamp_lcl = $timestamp_utc->setTimezone(new DateTimeZone($timezone));
    
    // Create string and return
    if ($timestamp_lcl != FALSE)
    {
      return $timestamp_lcl;
    } else
    {
      return -1;
    }
  }
  
  // Converts a string containing a time (hh:mm:ss) and a date object to a UTC datetime object
  function timestring_to_utc($timestring, $date, $timezone)
  {
    // Create date object
    $timestamp_lcl = new DateTime($date->format("Ymd") . "T" . $timestring, new DateTimeZone($timezone));
    
    // Convert to UTC
    $timestamp_utc = $timestamp_lcl->setTimezone(new DateTimeZone("UTC"));
    
    // Create string and return
    if ($timestamp_utc != FALSE)
    {
      return $timestamp_utc;
    } else
    {
      return -1;
    }
  }
  
  // Finds the nearest airport from the list
  function findAirport($latitude, $longitude)
  {
    global $airports;
    
    for ($i = 0; $i < count($airports); $i++)
    {
      $distance[$i] = distance($latitude, $longitude, $airports[$i]["latitude"], $airports[$i]["longitude"]);
      echo "Distance to " . $airports[$i]["name"] . " is " . $distance[$i] . "km<br />";
    }
    
    if (min($distance) < 50)
    {
      // Find the smallest distance. The possibility of two same distances is nearly zero
      return array_keys($distance, min($distance))[0]; //[0] to convert single field array to scalar value
    } else
    {
      return -1; 
    }
  }
  
  // Calculates the distance of two points on earth
  function distance($lat1, $lon1, $lat2, $lon2)
  {
    if (($lat1 == $lat2) && ($lon1 == $lon2)) {
      return 0;
    }
    else {
      $theta = $lon1 - $lon2;
      $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
      $dist = acos($dist);
      $dist = rad2deg($dist);
      $kilometers = $dist * 60 * 1.1515 * 1.609344;
      return $kilometers;
    }
  }
  
  
?>

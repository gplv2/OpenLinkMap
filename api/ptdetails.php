<?php
	/*
	OpenLinkMap Copyright (C) 2010 Alexander Matheisen
	This program comes with ABSOLUTELY NO WARRANTY.
	This is free software, and you are welcome to redistribute it under certain conditions.
	See http://wiki.openstreetmap.org/wiki/OpenLinkMap for details.
	*/


	require_once("functions.php");
	// including translation file
	require_once("../".includeLocale($_GET['lang']));

	$format = $_GET['format'];
	$id = $_GET['id'];
	$type = $_GET['type'];
	// offset of user's timezone to UTC
	$offset = offset($_GET['offset']);
	$callback = $_GET['callback'];

	date_default_timezone_set('UTC');

	// protection of sql injections
	if (!isValidType($type) || !isValidId($id))
	{
		echo "NULL";
		exit;
	}

	// get the most important langs of the user
	$langs = getLangs();
	if ($_GET['lang'])
		$langs[0] = $_GET['lang'];

	if (!getDetails($ptdb, $id, $type, $langs, $offset))
		echo "NULL";


	function getDetails($ptdb, $id, $type, $langs, $offset)
	{
		global $format, $callback;

		// request
		$wikipediarequest = "SELECT
								foo.keys, foo.values
							FROM (
								SELECT
									skeys(tags) AS keys,
									svals(tags) AS values
								FROM ".$type."s
								WHERE (id = ".$id.")
							) AS foo
							WHERE substring(foo.keys from 1 for 9) = 'wikipedia';";

		$namerequest = "SELECT
								foo.keys, foo.values
							FROM (
								SELECT
									skeys(tags) AS keys,
									svals(tags) AS values
								FROM ".$type."s
								WHERE (id = ".$id.")
							) AS foo
							WHERE substring(foo.keys from 1 for 4) = 'name';";

		// connnecting to database
		$connection = connectToDatabase($ptdb);
		// if there is no connection
		if (!$connection)
			exit;

		$wikipediaresponse = requestDetails($wikipediarequest, $connection);
		$nameresponse = requestDetails($namerequest, $connection);

		pg_close($connection);

		$response = tagTransform("../locales/timetables.xml", getTags($ptdb, $id, $type), $type);

		if ($response)
		{
			if ($format == "text")
				echo textDetailsOut($response, $nameresponse, $wikipediaresponse, $langs, $offset);
			else if ($format == "json")
				echo jsonDetailsOut($response, $nameresponse, $wikipediaresponse, $langs, $offset, $id, $type, $callback);
			else
				echo xmlDetailsOut($response, $nameresponse, $wikipediaresponse, $langs, $offset, $id, $type);
			return true;
		}
		else
			return false;
	}


	// output of details data in plain text format
	function textDetailsOut($response, $nameresponse, $wikipediaresponse, $langs = "en", $offset = 0)
	{
 		global $translations, $ptdb, $id, $type, $addressformats;

		if ($response)
		{
			// setting header
			header("Content-Type: text/html; charset=UTF-8");
			$output = "<meta http-equiv=\"content-type\" content=\"text/html; charset=UTF-8\">";

			// translation of name
			if ($nameresponse)
				$name = getNameDetail($langs, $nameresponse);

			// if no name is set, use the poi type as name instead
			if ($name[0] == "")
			{
				foreach ($response as $key => $value)
					if ($translations['tags'][$key][$value] != "")
						$name[0] = $translations['tags'][$key][$value];
			}

			$website = getWebsiteDetail(array($response['website'], $response['url'], $response['url:official'], $response['contact:website']));

			// get wikipedia link and make translation
			if ($wikipediaresponse)
				$wikipedia = getWikipediaDetail($langs, $wikipediaresponse);

			$departures = parse_url(urldecode($response['departures']));

			// printing popup details

			// image, only images from wikimedia are supported
			if (substr($response['image'], 0, 29) == "http://commons.wikimedia.org/" || substr($response['image'], 0, 28) == "http://upload.wikimedia.org/")
			{
				$url = getImageUrl($response['image']);
				$attribution = explode("/", $url);
				$output .= "<div id=\"loadingImage\"><img id=\"image\" title=\"".$translations['captions']['fullscreen']."\" src=\"".getWikipediaThumbnailUrl($url)."\" /></div></a>\n";
			}
			elseif (getWikipediaImage($wikipedia[1]))
			{
				$image = getWikipediaImage($wikipedia[1]);

				$output .= "<div id=\"loadingImage\"><img id=\"image\" title=\"".$translations['captions']['fullscreen']."\" src=\"".getWikipediaThumbnailUrl($image)."\" /></div></a>\n";
			}

			if ($name)
			{
				$output .= "<div class=\"container hcard vcard\"><div class=\"header\">\n";
				$output .= "<strong class=\"name\">".$name[0]."</strong>\n";
				$output .= "</div>\n";
			}

			// website and wikipedia links
			if ($website[0] || $wikipedia[0] || $response['departures'])
			{
				$output .= "<div class=\"web\">\n";
				if ($website[0])
				{
					if (($caption = strlen($website[1]) > 37) && (strlen($website[1]) > 40))
						$caption = substr($website[1], 0, 37)."...";
					else
						$caption = $website[1];
					$output .= "<div>".$translations['captions']['homepage'].": <a class=\"url\" target=\"_blank\" href=\"".$website[0]."\">".$caption."</a></div>\n";
				}
				if ($wikipedia[1])
					$output .= "<div class=\"wikipedia\">".$translations['captions']['wikipedia'].": <a target=\"_blank\" href=\"".$wikipedia[1]."\">".urldecode($wikipedia[2])."</a></div>\n";
				if ($response['departures'])
					$output .= "<div class=\"departures\">".$translations['captions']['departures'].": <a target=\"_blank\" href=\"".$response['departures']."\">".$departures['host']."</a></div>\n";
				$output .= "</div>\n";
			}

			// operator
			if ($response['operator'])
				$output .= "<div class=\"operator\">".$translations['captions']['operator'].": ".$response['operator']."</div>\n";

			$output .= "</div>\n";

			return $output;
		}
		else
			return false;
	}


	// output of details data in xml format
	function xmlDetailsOut($response, $nameresponse, $wikipediaresponse, $langs = "en", $offset = 0, $id, $type)
	{
		if ($response)
		{
			$output = xmlStart("details id=\"".$id."\" type=\"".$type."\"");

			$name = getNameDetail($langs, $nameresponse);

			$website = getWebsiteDetail(array($response['website'], $response['url'], $response['url:official'], $response['contact:website']));

			// get wikipedia link and make translation
			if ($wikipediaresponse)
				$wikipedia = getWikipediaDetail($langs, $wikipediaresponse);

			// printing popup details
			if ($name)
			{
				$output .= "<name";
				if ($name[0])
					$output .= " lang=\"".$name[1]."\"";
				$output .= ">".$name[0]."</name>\n";
			}

			// website and wikipedia links
			if ($website[0] || $wikipedia[0])
			{
				$output .= "<web>\n";
				if ($website[0])
					$output .= "<website>".$website[0]."</website>\n";
				if ($wikipedia[1])
					$output .= "<wikipedia>".$wikipedia[1]."</wikipedia>\n";
				$output .= "</web>\n";
			}

			// operator
			if ($response['operator'])
				$output .= "<operator>".$response['operator']."</operator>\n";

			// timetable departures
			if ($response['departures'])
				$output .= "<departures>".$response['departures']."</departures>\n";

			// image, only images from wikimedia are supported
			if (substr($response['image'], 14, 14) == "wikimedia.org/")
			{
				$url = getImageUrl($response['image']);
				$output .= "<image>";
 					$output .= $url;
				$output .= "</image>\n";
			}
			elseif (getWikipediaImage($wikipedia[1]))
			{
				$image = getWikipediaImage($wikipedia[1]);

				$output .= "<image>";
					$output .= $image;
				$output .= "</image>\n";
			}

			$output .= "</details>";

			return $output;
		}
		else
			return false;
	}


	// output of details data in json format
	function jsonDetailsOut($response, $nameresponse, $wikipediaresponse, $langs = "en", $offset = 0, $id, $type, $callback)
	{
		if ($response)
		{
			$name = getNameDetail($langs, $nameresponse);

			$website = getWebsiteDetail(array($response['website1'], $response['website2'], $response['website3'], $response['website4']));

			// get wikipedia link and make translation
			if ($wikipediaresponse)
				$wikipedia = getWikipediaDetail($langs, $wikipediaresponse);

			$data = array(
				'id' => (int)$id,
				'type' => $type,
			);

			// name
			if ($name)
			{
				if ($name[0])
					$data['name'] = array('lang' => $name[1], 'name' => $name[0]);
				else
					$data['name'] = $name[0];
			}

			// website and wikipedia links
			if ($website[0])
				$data['website'] = $website[0];
			if ($wikipedia[1])
				$data['wikipedia'] = $wikipedia[1];

			// operator
			if ($response['operator'])
				$data['operator'] = $response['operator'];

			// timetable departures
			if ($response['departures'])
				$data['departures'] = $response['departures'];

			// image, only images from wikimedia are supported
			if (substr($response['image'], 14, 14) == "wikimedia.org/")
				$data['image'] = getImageUrl($response['image']);
			else if (getWikipediaImage($wikipedia[1]))
				$data['image'] = getWikipediaImage($wikipedia[1]);

			$jsonData = json_encode($data);
			// JSONP request?
			if (isset($callback))
				return $callback.'('.$jsonData.')';
			else
				return $jsonData;
		}

		else
			return false;
	}
?>

<?php

/* see http://www.mediawiki.org/wiki/Manual:Special_pages
 * see http://www.mediawiki.org/wiki/Manual:Database_access */

class FbnTraffic extends SpecialPage {

	function __construct() {
		/* function SpecialPage( $name = '', $restriction = '', $listed = true, $function = false, $file = 'default', $includable = false )
		  $name = name in URLs */
		parent::__construct('FbnTraffic');
		wfLoadExtensionMessages('FbnTraffic');
	}

	function execute($par) {
		global $wgOut, $wgFbnTrafficDbIP, $wgFbnTrafficDbRadius;
		global $wgScriptPath;

		$wgOut->setPagetitle('Trafficanzeige');
		$wgOut->addExtensionStyle($wgScriptPath . '/extensions/FbnTraffic/FbnTraffic.css');
		$wgOut->addScriptFile($wgScriptPath . '/extensions/FbnTraffic/jquery-1.9.0.min.js');
		$wgOut->addScriptFile($wgScriptPath . '/extensions/FbnTraffic/bootstrap.min.js');
		$this->setHeaders();

		//	get currently logged in user
		$bn_name = $_SERVER["PHP_AUTH_USER"];

		if (!is_string($bn_name) OR empty($bn_name)) {
			$wgOut->addWikiText("Fehler: Es konnte kein Benutzername aus der HTTP-Authentifizierung ermittelt werden.");
			return;
		}

		// connect to databases
		$dbTrafficIP = new Database($wgFbnTrafficDbIP['host'], $wgFbnTrafficDbIP['user'], $wgFbnTrafficDbIP['pw'], $wgFbnTrafficDbIP['db']);
		$dbTrafficRadius = new Database($wgFbnTrafficDbRadius['host'], $wgFbnTrafficDbRadius['user'], $wgFbnTrafficDbRadius['pw'], $wgFbnTrafficDbRadius['db']);

		// get user data
		$user = $dbTrafficIP->selectRow(
				$wgFbnTrafficDbIP['tableUsers'], // $table
				array('BNID', 'Username', 'vorname', 'nachname', 'TrafficPunkteMax'), // $vars (columns of the table)
				array('Username LIKE \'' . $bn_name . '\'') // $conds
		);

		if ($user === false) {
			$wgOut->addWikiText("Fehler: Der Benutzername konnte nicht eindeutig bestimmt werden oder Sie haben keinen Netzzugang.");
			return;
		}

		$wgOut->addHTML("<p>für " . $user->vorname . " " . $user->nachname . " (Kunde " . $user->BNID . ") mit <em>Trafficlimit " .
				sprintf("%01.0f", $user->TrafficPunkteMax) . " MB innerhalb von 7 Tagen</em></p>");

		$wgOut->addHTML('
			<ul class="nav nav-tabs">
				<li class="active"><a href="#vpneinwahl" data-toggle="tab">VPN-Einwahl</a></li>
				<li><a href="#freigeschaltet" data-toggle="tab">IP-Freischaltung</a></li>
				<li><a href="#detail" data-toggle="tab">IP-Freischaltung Details</a></li>
				<li><a href="#wochenverlauf" data-toggle="tab">IP-Freischaltung Wochenverlauf</a></li>
				<li><a href="#monatsverlauf" data-toggle="tab">IP-Freischaltung Monatsverlauf</a></li>
			</ul>
			');
		$wgOut->addHTML("<div class=\"tab-content\" id=\"traffic\">\n");

		$wgOut->addHTML("<div class=\"tab-pane active\" id=\"vpneinwahl\">\n<h2>VPN-Einwahl</h2>\n");

		/* active VPN sessions */
		$trafficActiveVpnVars = array('COUNT(UserName) AS count');
		$trafficActiveVpnConds = array(
			'UNIX_TIMESTAMP(AcctStopTime)=0',
			'Username="' . $user->Username . '"',
			'UNIX_TIMESTAMP(AcctStartTime+AcctSessionTime) > UNIX_TIMESTAMP(DATE_SUB( now(),INTERVAL 600 SECOND ))',
		);
		$trafficActiveVpnOptions = array('GROUP BY' => 'Username');
		$trafficActiveVpn = $dbTrafficRadius->selectRow($wgFbnTrafficDbRadius['tableAccounting'], $trafficActiveVpnVars, $trafficActiveVpnConds, __METHOD__, $trafficActiveVpnOptions);
		//			SELECT count(UserName) as count
		//			FROM radacct
		//			WHERE UNIX_TIMESTAMP(AcctStopTime)="0" AND Username="' . $user->Username . '" AND
		//				UNIX_TIMESTAMP(AcctStartTime)+AcctSessionTime > UNIX_TIMESTAMP(DATE_SUB( now(),INTERVAL 600 SECOND))
		//			GROUP BY Username
		$wgOut->addHTML("<p>Anzahl der aktuell aktiven VPN-Verbindungen: " . $trafficActiveVpn->count . "</p>\n");

		/* VPN traffic per day */
		$vpnTrafficDailyDataVars = array('UNIX_TIMESTAMP(AcctStartTime) as datum', 'sum(`acctinputoctets`) as out_traffic', 'sum(`acctoutputoctets`) as in_traffic');
		$vpnTrafficDailyDataConds = array('Username="' . $user->Username . '"', 'UNIX_TIMESTAMP(AcctStartTime) + AcctSessionTime > UNIX_TIMESTAMP(DATE_SUB(now(), INTERVAL 7 DAY))');
		$vpnTrafficDailyDataOptions = array('GROUP BY' => 'Day(AcctStartTime)', 'ORDER BY' => 'datum DESC');
		$vpnTrafficDailyData = $dbTrafficRadius->select($wgFbnTrafficDbRadius['tableAccounting'], $vpnTrafficDailyDataVars, $vpnTrafficDailyDataConds, __METHOD__, $vpnTrafficDailyDataOptions);
		//		SELECT UNIX_TIMESTAMP(AcctStartTime) as datum, sum(`acctinputoctets`) as out_traffic, sum(`acctoutputoctets`) as in_traffic
		//		FROM `radacct`
		//		WHERE Username="' . $user->Username . '" AND
		//			UNIX_TIMESTAMP(AcctStartTime) + AcctSessionTime > UNIX_TIMESTAMP(DATE_SUB(now(), INTERVAL 7 DAY))
		//		GROUP BY Day(AcctStartTime)
		//		ORDER BY datum DESC
		$vpnTrafficDaily = array();
		foreach ($vpnTrafficDailyData as $vpnTrafficDay) {
			$vpnTrafficDaily[$vpnTrafficDay->datum]['in_traffic'] = $vpnTrafficDay->in_traffic;
			$vpnTrafficDaily[$vpnTrafficDay->datum]['out_traffic'] = $vpnTrafficDay->out_traffic;
		}

		$wgOut->addHTML("<table class=\"traffic sortable table table-condensed table-bordered table-striped\">
			<thead><tr>
			<th>Datum</th>
			<th>Download</th>
			<th>Upload</th>
			<th>Summe</th>
			<th width=\"50%\"></th>
			</tr></thead><tbody>");
		foreach ($vpnTrafficDaily as $datum => $traffic) {
			/* change Byte/s to MegaBytes/s */
			$traffic['in_traffic'] = sprintf("%8.2f", ($traffic['in_traffic'] / 1048576));
			$traffic['out_traffic'] = sprintf("%8.2f", ($traffic['out_traffic'] / 1048576));
			/* get percentage of the traffic */
			if ($user->TrafficPunkteMax > 0) {
				$limit = 100 * ($traffic['in_traffic'] + $traffic['out_traffic']) / $user->TrafficPunkteMax / 7;
			} else {
				$limit = 1;
			}
			if ($limit < 1)
				$limitData = $limit; $limit = 1;
			if ($limit > 100)
				$limit = 100;
			$wgOut->addHTML("<tr>\n");
			$wgOut->addHTML("<td sorttable_customkey=\"" . $datum . "\">" . strftime('%d.%m. %A', $datum) . "</td>\n");
			$wgOut->addHTML("<td>" . $traffic['in_traffic'] . " MB</td><td>" . $traffic['out_traffic'] . " MB</td><td>" . ($traffic['in_traffic'] + $traffic['out_traffic']) . " MB</td>\n");
			$wgOut->addHTML('<td><div class="progress progress-striped" title="' . sprintf("%01.2f", $limitData) . '%">' .
					'<div class="bar bar-success" style="width: ' . round($limit < 60 ? $limit : 60) . '%;">&nbsp;</div>' .
					($limit >= 60 ? '<div class="bar bar-warning" style="width: ' . round($limit < 90 ? $limit - 60 : 30) . '%;">&nbsp;</div>' : '') .
					($limit >= 90 ? '<div class="bar bar-danger" style="width: ' . round($limit < 100 ? $limit - 90 : 10) . '%;">&nbsp;</div>' : '') .
					"</div></td>\n");
			$wgOut->addHTML("</tr>\n");
		}
		$vpnTrafficDailyData->free();

		/* VPN traffic sum of days */
		$vpnTrafficDailySumVars = array('SUM(AcctInputOctets) as out_traffic', 'SUM(AcctOutputOctets) as in_traffic');
		$vpnTrafficDailySumConds = array('Username="' . $user->Username . '"', 'UNIX_TIMESTAMP(AcctStartTime) + AcctSessionTime > UNIX_TIMESTAMP(DATE_SUB(now(), INTERVAL 7 DAY))');
		$vpnTrafficDailySum = $dbTrafficRadius->selectRow($wgFbnTrafficDbRadius['tableAccounting'], $vpnTrafficDailySumVars, $vpnTrafficDailySumConds, __METHOD__);
		//		SELECT SUM(AcctInputOctets) as out_traffic, SUM(AcctOutputOctets) as in_traffic
		//		FROM radacct
		//		WHERE Username="' . $user->Username . '" AND
		//			UNIX_TIMESTAMP(AcctStartTime) + AcctSessionTime > UNIX_TIMESTAMP(DATE_SUB(now(), INTERVAL 7 DAY))

		/* change Byte/s to MegaBytes/s */
		$in_traffic = sprintf("%8.2f", ($vpnTrafficDailySum->in_traffic / 1048576));
		$out_traffic = sprintf("%8.2f", ($vpnTrafficDailySum->out_traffic / 1048576));
		/* get percentage of the traffic */
		if ($user->TrafficPunkteMax > 0) {
			$limit = 100 * ($in_traffic + $out_traffic) / $user->TrafficPunkteMax;
		} else {
			$limit = 1;
		}
		if ($limit < 1)
			$limitData = $limit; $limit = 1;
		if ($limit > 100)
			$limit = 100;
		$wgOut->addHTML("</tbody><tfoot>");
		$wgOut->addHTML("<tr><td>Summe</td><td>" . $in_traffic . " MB</td><td>" . $out_traffic . " MB</td><td>" . ($in_traffic + $out_traffic) . " MB</td>\n");
		$wgOut->addHTML('<td><div class="progress progress-striped" title="' . sprintf("%01.2f", $limitData) . '%">' .
				'<div class="bar bar-success" style="width: ' . round($limit < 60 ? $limit : 60) . '%;">&nbsp;</div>' .
				($limit >= 60 ? '<div class="bar bar-warning" style="width: ' . round($limit < 90 ? $limit - 60 : 30) . '%;">&nbsp;</div>' : '') .
				($limit >= 90 ? '<div class="bar bar-danger" style="width: ' . round($limit < 100 ? $limit - 90 : 10) . '%;">&nbsp;</div>' : '') .
				"</div></td>\n");
		$wgOut->addHTML("</tfoot></table>\n");
		$wgOut->addHTML("<p><em>Wochenlimit zu " . sprintf("%01.1f", $limitData) . "% erreicht.</em></p>");
		$wgOut->addHTML("</div>\n\n");

		/* IP addresses assigned to user */
		$wgOut->addHTML("<div class=\"tab-pane\" id=\"freigeschaltet\">\n<h2>aktuell freigeschaltete IP-Adressen</h2>\n");
		$ipList = $dbTrafficIP->select($wgFbnTrafficDbIP['tableIP'], array('ip'), array('bnid=' . intval($user->BNID)), __METHOD__);
		//		SELECT ip FROM IPs WHERE bnid = " . intval($user->BNID)
		foreach ($ipList as $ip) {
			$wgOut->addHTML("<span class=\"label label-success\">" . $ip->ip . "</span> \n");
		}
		$ipList->free();

		/* IP traffic per day in last week */
		$ipTrafficDailyDataVars = array('UNIX_TIMESTAMP(time) as time', 'sum(input) as input', 'sum(output) as output', 'sum(input + output) as sum', 'bnid');
		$ipTrafficDailyDataConds = array('bnid=' . intval($user->BNID), 'time > DATE_SUB(NOW(), INTERVAL 7 DAY)');
		$ipTrafficDailyDataOptions = array('GROUP BY' => 'DAY(time)', 'HAVING' => 'sum > 0', 'ORDER BY' => 'time DESC');
		$ipTrafficDailyData = $dbTrafficIP->select($wgFbnTrafficDbIP['tableTraffic'], $ipTrafficDailyDataVars, $ipTrafficDailyDataConds, __METHOD__, $ipTrafficDailyDataOptions);
		//		SELECT UNIX_TIMESTAMP(t.time) time, sum(t.input), sum(t.output), sum(t.input + t.output) as summe, t.bnid
		//		FROM traffic as t
		//		WHERE t.bnid=" . intval($user->BNID) . " AND t.time > DATE_SUB(NOW(), INTERVAL 7 DAY)
		//		GROUP BY DAY(t.time)
		//		HAVING summe > 0
		//		ORDER BY t.time DESC
		$sum_input = $sum_output = $sum_gesamt = 0;

		$wgOut->addHTML("<h3>Wochen&uuml;bersicht</h3>\n");
		$wgOut->addHTML("<table class=\"traffic sortable table table-condensed table-bordered table-striped\">\n");
		$wgOut->addHTML("<thead><tr>
			<th>Tag</th>
			<th>Download</th>
			<th>Upload</th>
			<th>Summe</th>
			<th width=\"50%\"></th>
			</tr></thead><tbody>\n");
		foreach ($ipTrafficDailyData as $datum => $ipTrafficDaily) {
			/* change Byte/s to MegaBytes/s */
			$inputtraffic = $ipTrafficDaily->input / 1048576;
			$outputtraffic = $ipTrafficDaily->output / 1048576;
			$gesamttraffic = $ipTrafficDaily->sum / 1048576;
			$sum_input += $inputtraffic;
			$sum_output += $outputtraffic;
			$sum_gesamt += $gesamttraffic;
			/* get percentage of the traffic */
			if ($user->TrafficPunkteMax > 0) {
				$limit = 100 * $gesamttraffic / $user->TrafficPunkteMax;
			} else {
				$limit = 1;
			}
			if ($limit < 1)
				$limitData = $limit; $limit = 1;
			if ($limit > 100)
				$limit = 100;

			$wgOut->addHTML("<tr>\n");
			$wgOut->addHTML("<td sorttable_customkey=\"" . $ipTrafficDaily->time . "\">" . strftime('%d.%m. %A', $ipTrafficDaily->time) . "</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $inputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $outputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $gesamttraffic) . " MB</td>\n");
			$wgOut->addHTML('<td><div class="progress progress-striped" title="' . sprintf("%01.2f", $limitData) . '%">' .
					'<div class="bar bar-success" style="width: ' . round($limit < 60 ? $limit : 60) . '%;">&nbsp;</div>' .
					($limit >= 60 ? '<div class="bar bar-warning" style="width: ' . round($limit < 90 ? $limit - 60 : 30) . '%;">&nbsp;</div>' : '') .
					($limit >= 90 ? '<div class="bar bar-danger" style="width: ' . round($limit < 100 ? $limit - 90 : 10) . '%;">&nbsp;</div>' : '') .
					"</div></td>\n");
			$wgOut->addHTML("</tr>\n");
		}
		$ipTrafficDailyData->free();

		/* IP traffic sum of week */
		if ($user->TrafficPunkteMax > 0) {
			$limit = 100 * $sum_gesamt / $user->TrafficPunkteMax;
		} else {
			$limit = 1;
		}
		if ($limit < 1)
			$limitData = $limit; $limit = 1;
		if ($limit > 100)
			$limit = 100;

		$wgOut->addHTML("</tbody><tfoot><tr>\n");
		$wgOut->addHTML("<td>Summe</td>\n");
		$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $sum_input) . " MB</td>\n");
		$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $sum_output) . " MB</td>\n");
		$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $sum_gesamt) . " MB</td>\n");
		$wgOut->addHTML('<td><div class="progress progress-striped" title="' . sprintf("%01.2f", $limitData) . '%">' .
				'<div class="bar bar-success" style="width: ' . round($limit < 60 ? $limit : 60) . '%;">&nbsp;</div>' .
				($limit >= 60 ? '<div class="bar bar-warning" style="width: ' . round($limit < 90 ? $limit - 60 : 30) . '%;">&nbsp;</div>' : '') .
				($limit >= 90 ? '<div class="bar bar-danger" style="width: ' . round($limit < 100 ? $limit - 90 : 10) . '%;">&nbsp;</div>' : '') .
				"</div></td>\n");
		$wgOut->addHTML("</tr>\n");
		$wgOut->addHTML("</tfoot></table>\n");
		$wgOut->addHTML("<p><em>Wochenlimit zu " . sprintf("%01.1f", $limitData) . "% erreicht.</em></p>");

		$ipTrafficSumDataVars = array('sum(input)/1048576 as input', 'sum(output)/1048576 as output', 'sum(input + output)/1048576 as sum');
		$ipTrafficSumDataConds = array('bnid=' . $user->BNID, 'time > DATE_SUB(NOW(), INTERVAL 2 HOUR)');
		$ipTrafficSumData = $dbTrafficIP->selectRow($wgFbnTrafficDbIP['tableTraffic'], $ipTrafficSumDataVars, $ipTrafficSumDataConds);
		//		SELECT sum(t.input)/1048576 input, sum(t.output)/1048576 output, sum(t.input + t.output)/1048576 summe
		//		FROM traffic t
		//		WHERE t.bnid=" . $user->BNID . " AND t.time > DATE_SUB(NOW(), INTERVAL 2 HOUR)
		$wgOut->addHTML("<h3>Trafficauswertung aller IP-Adressen der letzten 2 Stunden</h3>");
		$wgOut->addHTML("<table class=\"traffic sortable table table-condensed table-bordered table-striped\">\n");
		$wgOut->addHTML("<thead><tr>
			<th>Download</th>
			<th>Upload</th>
			<th>Summe</th>
			</tr></thead>\n");
		$wgOut->addHTML("<tbody><tr>\n");
		$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $ipTrafficSumData->input) . " MB</td>\n");
		$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $ipTrafficSumData->output) . " MB</td>\n");
		$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $ipTrafficSumData->sum) . " MB</td>\n");
		$wgOut->addHTML("</tr>\n");
		$wgOut->addHTML("</tbody></table>\n");
		$wgOut->addHTML("</div>\n");

		$wgOut->addHTML("<div class=\"tab-pane\" id=\"detail\">\n<h2>Detailierte Auswertung</h2>\n");

		/* accounted traffic per user in last hour */
		$ipTrafficLastHourDataVars = array('ip', 'sum(input) as input', 'sum(output) as output', 'sum(input + output) as sum', 'bnid');
		$ipTrafficLastHourDataConds = array('bnid=' . intval($user->BNID), 'time > DATE_SUB(NOW(), INTERVAL 1 hour)');
		$ipTrafficLastHourDataOptions = array('GROUP BY' => 'ip', 'HAVING' => 'sum > 0', 'ORDER BY' => 'ip DESC, time DESC');
		$ipTrafficLastHourData = $dbTrafficIP->select($wgFbnTrafficDbIP['tableTraffic'], $ipTrafficLastHourDataVars, $ipTrafficLastHourDataConds, __METHOD__, $ipTrafficLastHourDataOptions);
		//		SELECT t.ip, sum(t.input), sum(t.output), sum(t.input + t.output) as summe, t.bnid
		//		FROM traffic as t
		//		WHERE t.bnid=" . $user->BNID . " AND t.time > DATE_SUB(NOW(), INTERVAL 1 hour)
		//		GROUP BY ip
		//		HAVING summe > 0
		//		ORDER BY ip DESC

		$wgOut->addHTML("<h3>Traffic der letzten Stunde</h3>");
		$wgOut->addHTML("<table class=\"traffic sortable table table-condensed table-bordered table-striped\">\n");
		$wgOut->addHTML("<thead><tr>
			<th>IP-Adresse</th>
			<th>Download</th>
			<th>Upload</th>
			<th>Summe</th>
			<th width=\"50%\"></th>
			</tr></thead><tbody>\n");
		foreach ($ipTrafficLastHourData as $ipTrafficHour) {
			/* change Byte/s to MegaBytes/s */
			$inputtraffic = $ipTrafficHour->input / 1048576;
			$outputtraffic = $ipTrafficHour->output / 1048576;
			$gesamttraffic = $ipTrafficHour->sum / 1048576;
			/* get percentage of the traffic */
			$limit = 100 * $gesamttraffic / $user->TrafficPunkteMax;
			if ($limit < 1)
				$limitData = $limit; $limit = 1;
			if ($limit > 300)
				$limit = 300; /* max size of percentage bar */

			$wgOut->addHTML("<tr>\n");
			$wgOut->addHTML("<td>" . $ipTrafficHour->ip . "</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $inputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $outputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $gesamttraffic) . " MB</td>\n");
			$wgOut->addHTML('<td><div class="progress progress-striped" title="' . sprintf("%01.2f", $limitData) . '%">' .
					'<div class="bar bar-success" style="width: ' . round($limit < 60 ? $limit : 60) . '%;">&nbsp;</div>' .
					($limit >= 60 ? '<div class="bar bar-warning" style="width: ' . round($limit < 90 ? $limit - 60 : 30) . '%;">&nbsp;</div>' : '') .
					($limit >= 90 ? '<div class="bar bar-danger" style="width: ' . round($limit < 100 ? $limit - 90 : 10) . '%;">&nbsp;</div>' : '') .
					"</div></td>\n");
			$wgOut->addHTML("</tr>\n");
		}
		$ipTrafficLastHourData->free();
		$wgOut->addHTML("</tbody></table>\n");

		/* accounted traffic per user in last 2 hours */
		$ipTrafficLast2HoursDataVars = array('ip', 'sum(input) as input', 'sum(output) as output', 'sum(input + output) as sum', 'bnid');
		$ipTrafficLast2HoursDataConds = array('bnid=' . intval($user->BNID), 'time > DATE_SUB(NOW(), INTERVAL 2 hour)');
		$ipTrafficLast2HoursDataOptions = array('GROUP BY' => 'ip', 'HAVING' => 'sum > 0', 'ORDER BY' => 'ip DESC, time DESC');
		$ipTrafficLast2HoursData = $dbTrafficIP->select($wgFbnTrafficDbIP['tableTraffic'], $ipTrafficLast2HoursDataVars, $ipTrafficLast2HoursDataConds, __METHOD__, $ipTrafficLast2HoursDataOptions);
		//		SELECT t.ip, sum(t.input), sum(t.output), sum(t.input + t.output) as summe, t.bnid
		//		FROM traffic as t
		//		WHERE t.bnid=" . $user->BNID . " AND t.time > DATE_SUB(NOW(), INTERVAL 2 hour )
		//		GROUP BY ip
		//		HAVING summe > 0
		//		ORDER BY ip DESC

		$wgOut->addHTML("<h3>Traffic der letzten zwei Stunden</h3>");
		$wgOut->addHTML("<table class=\"traffic sortable table table-condensed table-bordered table-striped\">\n");
		$wgOut->addHTML("<thead><tr>
			<th>IP-Adresse</th>
			<th>Download</th>
			<th>Upload</th>
			<th>Summe</th>
			<th width=\"50%\"></th>
			</tr></thead><tbody>\n");
		foreach ($ipTrafficLast2HoursData as $ipTraffic2Hours) {
			/* change Byte/s to MegaBytes/s */
			$inputtraffic = $ipTraffic2Hours->input / 1048576;
			$outputtraffic = $ipTraffic2Hours->output / 1048576;
			$gesamttraffic = $ipTraffic2Hours->sum / 1048576;
			/* get percentage of the traffic */
			$limit = 100 * $gesamttraffic / $user->TrafficPunkteMax;
			if ($limit < 1)
				$limitData = $limit; $limit = 1;
			if ($limit > 300)
				$limit = 300; /* max size of percentage bar */

			$wgOut->addHTML("<tr>\n");
			$wgOut->addHTML("<td>" . $ipTraffic2Hours->ip . "</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $inputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $outputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $gesamttraffic) . " MB</td>\n");
			$wgOut->addHTML('<td><div class="progress progress-striped" title="' . sprintf("%01.2f", $limitData) . '%">' .
					'<div class="bar bar-success" style="width: ' . round($limit < 60 ? $limit : 60) . '%;">&nbsp;</div>' .
					($limit >= 60 ? '<div class="bar bar-warning" style="width: ' . round($limit < 90 ? $limit - 60 : 30) . '%;">&nbsp;</div>' : '') .
					($limit >= 90 ? '<div class="bar bar-danger" style="width: ' . round($limit < 100 ? $limit - 90 : 10) . '%;">&nbsp;</div>' : '') .
					"</div></td>\n");
			$wgOut->addHTML("</tr>\n");
		}
		$ipTrafficLast2HoursData->free();
		$wgOut->addHTML("</tbody></table>\n");

		/* accounted traffic per user in last day */
		$ipTrafficLastDayDataVars = array('ip', 'sum(input) as input', 'sum(output) as output', 'sum(input + output) as sum', 'bnid');
		$ipTrafficLastDayDataConds = array('bnid=' . intval($user->BNID), 'time > DATE_SUB(NOW(), INTERVAL 24 hour)');
		$ipTrafficLastDayDataOptions = array('GROUP BY' => 'ip', 'HAVING' => 'sum > 0', 'ORDER BY' => 'ip DESC, time DESC');
		$ipTrafficLastDayData = $dbTrafficIP->select($wgFbnTrafficDbIP['tableTraffic'], $ipTrafficLastDayDataVars, $ipTrafficLastDayDataConds, __METHOD__, $ipTrafficLastDayDataOptions);
		//		SELECT t.ip, sum(t.input), sum(t.output), sum(t.input + t.output) as summe, t.bnid
		//		FROM traffic as t
		//		WHERE t.bnid=" . $user->BNID . " AND t.time > DATE_SUB(NOW(), INTERVAL 24 hour )
		//		GROUP BY ip
		//		HAVING summe > 0
		//		ORDER BY ip DESC

		$wgOut->addHTML("<h3>Traffic des letzten Tages</h3>");
		$wgOut->addHTML("<table class=\"traffic sortable table table-condensed table-bordered table-striped\">\n");
		$wgOut->addHTML("<thead><tr>
			<th>IP-Adresse</th>
			<th>Download</th>
			<th>Upload</th>
			<th>Summe</th>
			<th width=\"50%\"></th>
			</tr></thead><tbody>\n");
		foreach ($ipTrafficLastDayData as $ipTrafficDay) {
			/* change Byte/s to MegaBytes/s */
			$inputtraffic = $ipTrafficDay->input / 1048576;
			$outputtraffic = $ipTrafficDay->output / 1048576;
			$gesamttraffic = $ipTrafficDay->sum / 1048576;
			/* get percentage of the traffic */
			$limit = 100 * $gesamttraffic / $user->TrafficPunkteMax;
			if ($limit < 1)
				$limitData = $limit; $limit = 1;
			if ($limit > 300)
				$limit = 300; /* max size of percentage bar */

			$wgOut->addHTML("<tr>\n");
			$wgOut->addHTML("<td>" . $ipTrafficDay->ip . "</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $inputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $outputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $gesamttraffic) . " MB</td>\n");
			$wgOut->addHTML('<td><div class="progress progress-striped" title="' . sprintf("%01.2f", $limitData) . '%">' .
					'<div class="bar bar-success" style="width: ' . round($limit < 60 ? $limit : 60) . '%;">&nbsp;</div>' .
					($limit >= 60 ? '<div class="bar bar-warning" style="width: ' . round($limit < 90 ? $limit - 60 : 30) . '%;">&nbsp;</div>' : '') .
					($limit >= 90 ? '<div class="bar bar-danger" style="width: ' . round($limit < 100 ? $limit - 90 : 10) . '%;">&nbsp;</div>' : '') .
					"</div></td>\n");
			$wgOut->addHTML("</tr>\n");
		}
		$ipTrafficLastDayData->free();
		$wgOut->addHTML("</tbody></table>\n");

		/* accounted traffic per user in last week */
		$ipTrafficLastWeekDataVars = array('ip', 'sum(input) as input', 'sum(output) as output', 'sum(input + output) as sum', 'bnid');
		$ipTrafficLastWeekDataConds = array('bnid=' . intval($user->BNID), 'time > DATE_SUB(NOW(), INTERVAL 7 day)');
		$ipTrafficLastWeekDataOptions = array('GROUP BY' => 'ip', 'HAVING' => 'sum > 0', 'ORDER BY' => 'ip DESC, time DESC');
		$ipTrafficLastWeekData = $dbTrafficIP->select($wgFbnTrafficDbIP['tableTraffic'], $ipTrafficLastWeekDataVars, $ipTrafficLastWeekDataConds, __METHOD__, $ipTrafficLastWeekDataOptions);
		//		SELECT t.ip, sum(t.input), sum(t.output), sum(t.input + t.output) as summe, t.bnid
		//		FROM traffic as t
		//		WHERE t.bnid=" . $user->BNID . " AND t.time > DATE_SUB(NOW(), INTERVAL 7 day )
		//		GROUP BY ip
		//		HAVING summe > 0
		//		ORDER BY ip DESC

		$wgOut->addHTML("<h3>Traffic der letzten Woche</h3>");
		$wgOut->addHTML("<table class=\"traffic sortable table table-condensed table-bordered table-striped\">\n");
		$wgOut->addHTML("<thead><tr>
			<th>IP-Adresse</th>
			<th>Download</th>
			<th>Upload</th>
			<th>Summe</th>
			<th width=\"50%\"></th>
			</tr></thead><tbody>\n");
		foreach ($ipTrafficLastWeekData as $ipTrafficWeek) {
			/* change Byte/s to MegaBytes/s */
			$inputtraffic = $ipTrafficWeek->input / 1048576;
			$outputtraffic = $ipTrafficWeek->output / 1048576;
			$gesamttraffic = $ipTrafficWeek->sum / 1048576;
			/* get percentage of the traffic */
			$limit = 100 * $gesamttraffic / $user->TrafficPunkteMax;
			if ($limit < 1)
				$limitData = $limit; $limit = 1;
			if ($limit > 300)
				$limit = 300; /* max size of percentage bar */

			$wgOut->addHTML("<tr>\n");
			$wgOut->addHTML("<td>" . $ipTrafficWeek->ip . "</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $inputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $outputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $gesamttraffic) . " MB</td>\n");
			$wgOut->addHTML('<td><div class="progress progress-striped" title="' . sprintf("%01.2f", $limitData) . '%">' .
					'<div class="bar bar-success" style="width: ' . round($limit < 60 ? $limit : 60) . '%;">&nbsp;</div>' .
					($limit >= 60 ? '<div class="bar bar-warning" style="width: ' . round($limit < 90 ? $limit - 60 : 30) . '%;">&nbsp;</div>' : '') .
					($limit >= 90 ? '<div class="bar bar-danger" style="width: ' . round($limit < 100 ? $limit - 90 : 10) . '%;">&nbsp;</div>' : '') .
					"</div></td>\n");
			$wgOut->addHTML("</tr>\n");
		}
		$ipTrafficLastWeekData->free();
		$wgOut->addHTML("</tbody></table>\n</div>\n");

		/* IP traffic in last week per hour */
		$ipTrafficLastWeekHoursDataVars = array('ip', 'input', 'output', '(input + output) as sum', 'UNIX_TIMESTAMP(time) as time');
		$ipTrafficLastWeekHoursDataConds = array('bnid=' . intval($user->BNID), 'time > DATE_SUB(NOW(), INTERVAL 7 day)');
		$ipTrafficLastWeekHoursDataOptions = array('ORDER BY' => 'ip DESC, time DESC');
		$ipTrafficLastWeekHoursData = $dbTrafficIP->select($wgFbnTrafficDbIP['tableTraffic'], $ipTrafficLastWeekHoursDataVars, $ipTrafficLastWeekHoursDataConds, __METHOD__, $ipTrafficLastWeekHoursDataOptions);
		//		SELECT t.input, t.output, t.input + t.output as summe, t.ip, UNIX_TIMESTAMP(time)
		//		FROM traffic as t
		//		WHERE t.bnid=" . $user->BNID . " AND t.time > DATE_SUB(NOW(), INTERVAL 7 day)
		//		ORDER BY t.ip DESC, t.time DESC

		$wgOut->addHTML("<div class=\"tab-pane\" id=\"wochenverlauf\">\n<h2>Verlauf der letzten Woche</h2>\n");
		$wgOut->addHTML("<table class=\"traffic sortable table table-condensed table-bordered table-striped\">\n");
		$lastIp = 0;
		foreach ($ipTrafficLastWeekHoursData as $ipTrafficWeekHour) {
			/* change Byte/s to MegaBytes/s */
			$inputtraffic = $ipTrafficWeekHour->input / 1048576;
			$outputtraffic = $ipTrafficWeekHour->output / 1048576;
			$gesamttraffic = $ipTrafficWeekHour->sum / 1048576;
			if ($ipTrafficWeekHour->ip != $lastIp) {
				$wgOut->addHTML("<tr><th colspan=\"6\">Datensätze für die IP-Adresse " . $ipTrafficWeekHour->ip . "</th></tr>\n");
				$wgOut->addHTML("<thead><tr>
					<th>IP-Adresse</th>
					<th>Stunde</th>
					<th>Download</th>
					<th>Upload</th>
					<th>Summe</th>
					<th width=\"50%\"></th>
					</tr></thead><tbody>\n");
			}
			/* get percentage of the traffic */
			$limit = 100 * $gesamttraffic / ($user->TrafficPunkteMax / 7);
			if ($limit < 1)
				$limitData = $limit; $limit = 1;
			if ($limit > 300)
				$limit = 300; /* max size of percentage bar */

			$wgOut->addHTML("<tr>\n");
			$wgOut->addHTML("<td>" . $ipTrafficWeekHour->ip . "</td>\n");
			$wgOut->addHTML("<td sorttable_customkey=\"" . $ipTrafficWeekHour->time . "\">" . strftime('%d.%m.%Y - %H', $ipTrafficWeekHour->time) . " Uhr</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $inputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $outputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $gesamttraffic) . " MB</td>\n");
			$wgOut->addHTML('<td><div class="progress progress-striped" title="' . sprintf("%01.2f", $limitData) . '%">' .
					'<div class="bar bar-success" style="width: ' . round($limit < 60 ? $limit : 60) . '%;">&nbsp;</div>' .
					($limit >= 60 ? '<div class="bar bar-warning" style="width: ' . round($limit < 90 ? $limit - 60 : 30) . '%;">&nbsp;</div>' : '') .
					($limit >= 90 ? '<div class="bar bar-danger" style="width: ' . round($limit < 100 ? $limit - 90 : 10) . '%;">&nbsp;</div>' : '') .
					"</div></td>\n");
			$wgOut->addHTML("</tr>\n");

			$lastIp = $ipTrafficWeekHour->ip;
		}
		$ipTrafficLastWeekHoursData->free();
		if ($lastIp == 0) {
			$wgOut->addHTML("<thead><tr>
				<th>IP-Adresse</th>
				<th>Stunde</th>
				<th>Download</th>
				<th>Upload</th>
				<th>Summe</th>
				<th width=\"50%\"></th>
				</tr></thead><tbody>\n");
		}
		$wgOut->addHTML("</tbody></table>\n</div>\n");

		/* IP traffic per month in last 12 month */
		$ipTrafficMonthlyDataVars = array('UNIX_TIMESTAMP(time) AS time', 'sum(input) as input', 'sum(output) as output', 'sum(input + output) as sum', 'bnid');
		$ipTrafficMonthlyDataConds = array('bnid=' . intval($user->BNID));
		$ipTrafficMonthlyDataOptions = array('GROUP BY' => 'DATE_FORMAT(time,\'%Y%m\')', 'HAVING' => 'sum > 0', 'ORDER BY' => 'time DESC');
		$ipTrafficMonthlyData = $dbTrafficIP->select($wgFbnTrafficDbIP['tableTraffic'], $ipTrafficMonthlyDataVars, $ipTrafficMonthlyDataConds, __METHOD__, $ipTrafficMonthlyDataOptions);
		//		SELECT
		//			UNIX_TIMESTAMP(t.time) AS time,
		//			sum(t.input),
		//			sum(t.output),
		//			sum(t.input + t.output) AS summe,
		//			t.bnid
		//		FROM traffic AS t
		//		WHERE t.bnid=" . intval($user->BNID) . "
		//		GROUP BY DATE_FORMAT(t.time,'%Y%m')
		//		HAVING summe > 0
		//		ORDER BY t.time DESC

		$wgOut->addHTML("<div class=\"tab-pane\" id=\"monatsverlauf\">\n<h2>Traffic der letzten 12 Monate</h2>\n");
		$wgOut->addHTML("<table class=\"traffic sortable table table-condensed table-bordered table-striped\">\n");
		$wgOut->addHTML("<thead><tr>
			<th>Monat</th>
			<th>Download</th>
			<th>Upload</th>
			<th>Summe</th>
			<th width=\"50%\"></th>
			</tr></thead><tbody>\n");
		foreach ($ipTrafficMonthlyData as $ipTrafficMonth) {
			$inputtraffic = $ipTrafficMonth->input / 1048576;
			$outputtraffic = $ipTrafficMonth->output / 1048576;
			$gesamttraffic = $ipTrafficMonth->sum / 1048576;
			/* get percentage of the traffic */
			/* calculate weeks per month; 4 weeks = 28 days but we have 28-31 days per month */
			$weeks = date('t', $ipTrafficMonth->time) / 7;
			$limit = 100 * $gesamttraffic / ($user->TrafficPunkteMax * $weeks);

			if ($limit < 1)
				$limitData = $limit; $limit = 1;
			if ($limit > 300)
				$limit = 300; /* max size of percentage bar */

			$wgOut->addHTML("<tr>\n");
			$wgOut->addHTML("<td sorttable_customkey=\"" . $ipTrafficMonth->time . "\">" . strftime('%Y - %B', $ipTrafficMonth->time) . "</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $inputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $outputtraffic) . " MB</td>\n");
			$wgOut->addHTML("<td class=\"number\">" . sprintf("%01.2f", $gesamttraffic) . " MB</td>\n");
			$wgOut->addHTML('<td><div class="progress progress-striped" title="' . sprintf("%01.2f", $limitData) . '%">' .
					'<div class="bar bar-success" style="width: ' . round($limit < 60 ? $limit : 60) . '%;">&nbsp;</div>' .
					($limit >= 60 ? '<div class="bar bar-warning" style="width: ' . round($limit < 90 ? $limit - 60 : 30) . '%;">&nbsp;</div>' : '') .
					($limit >= 90 ? '<div class="bar bar-danger" style="width: ' . round($limit < 100 ? $limit - 90 : 10) . '%;">&nbsp;</div>' : '') .
					"</div></td>\n");
			$wgOut->addHTML("</tr>\n");
		}
		$ipTrafficMonthlyData->free();
		$wgOut->addHTML("</tbody></table>\n");
		$wgOut->addHTML("</div>\n\n");

		$wgOut->addHTML("</div>\n\n");
	}

// execute()
}

// class

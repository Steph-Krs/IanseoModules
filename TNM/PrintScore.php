<?php
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
CheckTourSession(true);
checkFullACL(AclRobin, '', AclReadOnly);
// require_once('Common/Globals.inc.php');
// require_once('Common/Fun_DB.inc.php');
require_once('Common/Lib/CommonLib.php');
// require_once('Common/Lib/Fun_Phases.inc.php');
// require_once('Common/Lib/Fun_FormatText.inc.php');
//require_once('HHT/Fun_HHT.local.inc.php');

$Team=intval($_REQUEST['team']??-1);

$IncludeJquery = true;
$JS_SCRIPT=array(
	phpVars2js(array("WebDir" => $CFG->ROOT_DIR, "AllEvents" => get_text('AllEvents'))),
	'<script type="text/javascript" src="./PrintScore.js"></script>',
	);

include('Common/Templates/head.php');

echo '<table class="Tabella">';

echo '<tr>
	<th class="Title" colspan="4">' . get_text('PrintScore','Tournament')  . '</th>
	</tr>';

echo '<tr>
	<th class="SubTitle" colspan="4"><select id="TeamSelector" onchange="getEvents()">
		<option value="-1">---</option>
		<option value="0"'.($Team==0 ? ' selected="selected"' : '').'>'.get_text('Individual').'</option>
		<option value="1"'.($Team==1 ? ' selected="selected"' : '').'>'.get_text('Team').'</option>
		</select></th>
	</tr>';

echo '<tbody id="mainTdBody">';

/**********************************
 *
 * Manual Selection
 *
 *********************************/
echo '<tr>';
echo '<td class="Center w-40"><select id="EventSelector" class="w-90" multiple="multiple" size="10" onchange="getLevels()"></select></td>';
echo '<td class="Center w-20"><select id="LevelSelector" class="w-90" multiple="multiple" size="10" onchange="getGroups()"></select></td>';
echo '<td class="Center w-20"><select id="GroupSelector" class="w-90" multiple="multiple" size="10" onchange="getRounds()"></select></td>';
echo '<td class="Center w-20"><select id="RoundSelector" class="w-90" multiple="multiple" size="10"></select></td>';
echo '</tr>';

/**********************************
 *
 * Scheduler Selection
 *
 *********************************/
echo '<tr>';
echo '<td colspan="4" class="Center">' . ApiComboSession(['R'], 'ScheduleSelector') . '</td>';
echo '</tr>';

/**********************************
 *
 * Options
 *
 *********************************/
echo '<tr>';
echo '<td colspan="4" class="Center">';
echo '<div class="Left" style="display: inline-block">';
echo '<input class="includeInForm" id="ScoreFilled" type="checkbox" value="1">&nbsp;' . get_text('ScoreFilled') . '<br>';
echo '<input class="includeInForm" id="IncEmpty" type="checkbox" value="1">&nbsp;' . get_text('ScoreIncEmpty') . '<br>';
echo '<input class="includeInForm" id="ScoreFlags" type="checkbox" value="1">&nbsp;' . get_text('ScoreFlags','Tournament') . '<br>';
if(module_exists("Barcodes")) {
	echo '<input class="includeInForm" id="Barcode" type="checkbox" checked value="1">&nbsp;' . get_text('ScoreBarcode','Tournament') . '<br>';
}
if($_SESSION['TourLocRule']=='LANC') {
    // specific fro lancaster
    echo '<input class="includeInForm" id="Margins" type="checkbox" checked value="1" >&nbsp;' . get_text('LancasterScorecard','Tournament') . '<br>';
    echo '<input class="includeInForm" id="TopMargin" type="number" value="165" >&nbsp;' . get_text('IdMarginT','BackNumbers') . '<br>';
    echo '<input class="includeInForm" id="LeftMargin" type="number" value="180" >&nbsp;' . get_text('IdMarginL','BackNumbers') . '<br>';
}
foreach(AvailableApis() as $Api) {
    if(!($tmp=getModuleParameter($Api, 'Mode')) || strpos($tmp,'live') !== false) {
        continue;
    }
	echo '<input name="QRCode[]" type="checkbox" '.(strpos($tmp,'pro')!== false ? '' : 'checked="checked"').' value="'.$Api.'" >&nbsp;' . get_text($Api.'-QRCode','Api') . '<br>';
}
echo '<input class="includeInForm" id="IncBye" type="checkbox" checked value="1">&nbsp;Imprimer les BYE<br>';
echo '</div>';
echo '</td>';
echo '</tr>';

echo '<tr>
	<td colspan="4" class="Center"><div class="my-3"><div class="Button" onclick="createScorecards()">' . get_text('PrintScore','Tournament') . '</div></td>
	</tr>';

echo '</tbody>';
echo '</table>';

include('Common/Templates/tail.php');


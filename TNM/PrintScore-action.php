<?php
$JSON=['error'=>1, 'msg'=>''];
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');

if(!CheckTourSession() or !hasFullACL(AclRobin, '', AclReadOnly)) {
	JsonOut($JSON);
}

$Act=($_REQUEST['act']??'');
$Team=intval($_REQUEST['team'] ?? -1);
$Events=($_REQUEST['events']??[]);
$Levels=($_REQUEST['levels']??[]);
$Groups=($_REQUEST['groups']??[]);
$Rounds=($_REQUEST['rounds']??[]);

if(!$Act or $Team==-1) {
	JsonOut($JSON);
}

switch($Act) {
	case 'getEvents':
		$JSON['events']=[['v'=>'','t'=>get_text('AllEvents')]];
		$q=safe_w_sql("select EvCode as v, concat_ws('-',EvCode,EvEventName) as t from Events where EvTournament={$_SESSION['TourId']} and EvTeamEvent=$Team and EvElimType=5 order by EvProgr");
		while($r=safe_fetch($q)) {
			$JSON['events'][]=$r;
		}
		$JSON['error']=0;
		break;
	case 'getLevels':
		$JSON['levels']=[['v'=>'','t'=>get_text('AllEvents')]];
		$f=array();
		foreach($Events as $e) {
			if(!$e) {
				// all events!
				$f=[];
				break;
			}
			$f[]=$e;
		}
		$filter=($f ? ' AND RrLevEvent in ('.implode(',', StrSafe_DB($f)).')' : '');
		$q=safe_w_sql("select distinct RrLevLevel as v, group_concat(distinct RrLevName) as t from RoundRobinLevel where RrLevTournament={$_SESSION['TourId']} and RrLevTeam=$Team $filter group by RrLevLevel order by RrLevLevel");
		while($r=safe_fetch($q)) {
			$JSON['levels'][]=$r;
		}
		$JSON['error']=0;
		break;
	case 'getGroups':
		$JSON['groups']=[['v'=>'','t'=>get_text('AllEvents')]];
		$f2=[];
		$f=array();
		foreach($Events as $e) {
			if(!$e) {
				// all events!
				$f=[];
				break;
			}
			$f[]=$e;
		}
		if($f) {
			$f2[]=($f ? ' AND RrGrEvent in ('.implode(',', StrSafe_DB($f)).')' : '');
		}
		$f=array();
		foreach($Levels as $e) {
			if(!$e) {
				// all levels!
				$f=[];
				break;
			}
			$f[]=$e;
		}
		if($f) {
			$f2[]=($f ? ' AND RrGrLevel in ('.implode(',', StrSafe_DB($f)).')' : '');
		}
		$filter=($f2 ? implode($f2) : '');
		$q=safe_w_sql("select distinct RrGrGroup as v, group_concat(distinct RrGrName) as t from RoundRobinGroup where RrGrTournament={$_SESSION['TourId']} and RrGrTeam=$Team $filter group by RrGrGroup order by RrGrGroup");
		while($r=safe_fetch($q)) {
			$JSON['groups'][]=$r;
		}
		$JSON['error']=0;
		break;
	case 'getRounds':
		$JSON['rounds']=[['v'=>'','t'=>get_text('AllEvents')]];
		$f2=[];
		$f=array();
		foreach($Events as $e) {
			if(!$e) {
				// all events!
				$f=[];
				break;
			}
			$f[]=$e;
		}
		if($f) {
			$f2[]=($f ? ' AND RrMatchEvent in ('.implode(',', StrSafe_DB($f)).')' : '');
		}
		$f=array();
		foreach($Levels as $e) {
			if(!$e) {
				// all levels!
				$f=[];
				break;
			}
			$f[]=$e;
		}
		if($f) {
			$f2[]=($f ? ' AND RrMatchLevel in ('.implode(',', StrSafe_DB($f)).')' : '');
		}
		$f=array();
		foreach($Groups as $e) {
			if(!$e) {
				// all levels!
				$f=[];
				break;
			}
			$f[]=$e;
		}
		if($f) {
			$f2[]=($f ? ' AND RrMatchGroup in ('.implode(',', StrSafe_DB($f)).')' : '');
		}
		$filter=($f2 ? implode($f2) : '');
		$q=safe_w_sql("select distinct RrMatchRound as v from RoundRobinMatches where RrMatchTournament={$_SESSION['TourId']} and RrMatchTeam=$Team $filter group by RrMatchRound order by RrMatchRound");
		while($r=safe_fetch($q)) {
			$r->t=get_text('RoundNum', 'RoundRobin', $r->v);
			$JSON['rounds'][]=$r;
		}
		$JSON['error']=0;
		break;
	default:
		JsonOut($JSON);
}

JsonOut($JSON);


<?php
/**
 * Spanish strings of the scheduled upload module. Keys missing here fall back
 * to the English file, which holds the reference list.
 */

$lang['ModuleName'] = 'Envío programado';
$lang['MenuSettings'] = 'Ajustes y estado';
$lang['MenuUpdate'] = 'Actualizar el módulo';
$lang['UpdateTitle'] = 'Envío programado — actualización';
$lang['BackToSettings'] = 'Volver a los ajustes';

$lang['PageTitle'] = 'Envío programado a ianseo.net';
$lang['PageLead'] = 'Abre y cierra la introducción de puntuaciones y envía los resultados a ianseo.net por sí solo, a las horas fijadas abajo, sin un ordenador dejado en la página de envío. El trabajo lo hace una tarea que el servidor ejecuta cada minuto.';

$lang['WarnNoCredentials'] = 'Los códigos ianseo.net de esta competición no están guardados: no es posible ningún envío. Introdúzcalos marcando la casilla «recordar»:';
$lang['WarnSimulation'] = 'Modo simulación: los resultados se construyen como para un envío real, pero no se envía nada a ianseo.net. Desmárquelo abajo antes del periodo real.';

$lang['StatusTitle'] = 'Estado';
$lang['Loading'] = 'Cargando…';

$lang['PeriodTitle'] = 'Periodo';
$lang['Enabled'] = 'Programación activa';
$lang['EnabledHint'] = 'Sin marcar, no se hace nada automáticamente y la introducción queda como está.';
$lang['Start'] = 'Inicio';
$lang['End'] = 'Fin';
$lang['TimeZone'] = 'Zona horaria';
$lang['Interval'] = 'Envío cada';
$lang['Minutes'] = 'minutos';
$lang['PeriodSaved'] = 'Periodo guardado: del {$a[start]} al {$a[end]}.';
$lang['TimeZoneHint'] = 'Las horas son las de la zona elegida, cambios de hora incluidos: un periodo que empieza en horario de invierno y termina en horario de verano conserva las horas introducidas aquí. Una hora que no existe la noche del cambio (02:00–03:00 en primavera) se rechaza.';
$lang['IntervalHint'] = 'Al menos {$a} minutos. Tras un fallo, el siguiente intento llega antes (1, 2, 4… minutos), y el ritmo normal se reanuda con el primer éxito.';

$lang['SessionsTitle'] = 'Introducción de puntuaciones (ISK-NG)';
$lang['SessionsNoRight'] = 'Sus permisos no permiten gestionar la introducción ISK-NG: estos ajustes se muestran en solo lectura.';
$lang['SessionsManage'] = 'Abrir la introducción al inicio y cerrarla al final';
$lang['SessionsHint'] = 'Las sesiones marcadas se abren al inicio y se cierran al final, una sola vez cada una: una sesión bloqueada a mano durante el periodo sigue bloqueada. Al final, la introducción se cierra antes del último envío, para que contenga todas las puntuaciones introducidas.';
$lang['SessionsNone'] = 'Todavía no hay ninguna sesión de introducción en esta competición.';

$lang['ItemsTitle'] = 'Lo que se envía';
$lang['ItemsHint'] = 'Las mismas listas que en la página de envío de ianseo. Un elemento marcado «todavía no disponible» se envía en cuanto ianseo lo propone (cuadros una vez resueltos los desempates, medallas una vez otorgadas).';
$lang['ItemWaiting'] = '(todavía no disponible)';
$lang['ItemMissing'] = '(ya no existe en la competición)';

$lang['PingTitle'] = 'Vigilancia externa (opcional)';
$lang['PingUrl'] = 'Dirección de vigilancia (healthchecks.io o compatible)';
$lang['PingHint'] = 'El servidor llama a esta dirección tras cada envío y al menos una vez por intervalo. Si las llamadas se detienen — servidor apagado, red cortada, envíos fallidos — el servicio de vigilancia le avisa por correo electrónico o SMS. No se envía nada en simulación. Vea el README del módulo.';

$lang['SimulationTitle'] = 'Simulación';
$lang['Simulation'] = 'Simulación: no enviar nada a ianseo.net';
$lang['SimulationHint'] = 'Todo ocurre de verdad — horarios, introducción, construcción de los resultados — salvo el envío en sí, que se imita. Útil para comprobar los ajustes y medir la carga en el servidor antes del periodo real.';

$lang['Save'] = 'Guardar';

$lang['TaskTitle'] = 'Tarea programada en el servidor';
$lang['TaskExplain'] = 'PHP solo se ejecuta cuando se pide una página: nada dentro de ianseo puede despertarse solo a una hora dada. El programador del servidor ejecuta, por tanto, la tarea del módulo cada minuto; esta mira lo que corresponde (apertura, envío, cierre) y se detiene de inmediato cuando no hay nada. Sin esta tarea, no ocurre nada.';
$lang['TaskInstallLinux'] = 'A instalar una vez, como administrador del servidor (Linux, servidor web con el usuario www-data):';
$lang['TaskInstallWindows'] = 'A instalar una vez, en un símbolo del sistema abierto como administrador:';
$lang['TaskReadme'] = 'El README del módulo explica cómo comprobar que la tarea funciona y cómo quitarla.';

$lang['HeartbeatOk'] = 'La tarea programada funciona.';
$lang['HeartbeatNever'] = 'La tarea programada nunca se ha ejecutado en este servidor: vea «Tarea programada en el servidor» más abajo.';
$lang['HeartbeatLate'] = 'La tarea programada no se ha ejecutado desde hace {$a} minutos.';

$lang['PhaseDisabled'] = 'Programación inactiva.';
$lang['PhaseIncomplete'] = 'Programación incompleta: falta el inicio o el fin.';
$lang['PhaseWaiting'] = 'Esperando el inicio, el {$a}.';
$lang['PhaseRunning'] = 'Periodo en curso, hasta el {$a}.';
$lang['PhaseFinishing'] = 'Periodo terminado: último envío en curso.';
$lang['PhaseDone'] = 'Periodo terminado desde el {$a}.';

$lang['KindSend'] = 'Envío';
$lang['KindManual'] = 'Envío solicitado';
$lang['KindFinal'] = 'Último envío';
$lang['KindOpen'] = 'Introducción abierta';
$lang['KindClose'] = 'Introducción cerrada';

$lang['OutSent'] = 'Enviado a ianseo.net.';
$lang['OutSentSimulated'] = 'Simulado: construido, nada enviado.';
$lang['OutNothingYet'] = 'Todavía nada que enviar: los elementos elegidos no están disponibles.';
$lang['OutSessionsOpened'] = 'Introducción abierta:';
$lang['OutSessionsClosed'] = 'Introducción cerrada:';
$lang['OutErrNoPlan'] = 'No hay programación para esta competición.';
$lang['OutErrNoCompetition'] = 'La competición ya no existe.';
$lang['OutErrNothingSelected'] = 'No se ha elegido nada para enviar.';
$lang['OutErrNoCredentials'] = 'Los códigos ianseo.net de la competición no están guardados.';
$lang['OutErrPublicationLocked'] = 'La publicación está bloqueada para esta competición.';
$lang['OutErrCredentialsCheck'] = 'ianseo.net inaccesible, o códigos rechazados:';
$lang['OutErrIanseoNet'] = 'ianseo.net rechazó el envío:';
$lang['OutErrWorker'] = 'El envío se detuvo con un error:';
$lang['OutErrTimeout'] = 'El envío duró más de {$a} segundos y se detuvo.';
$lang['OutErrStart'] = 'No se pudo iniciar el envío en el servidor.';

$lang['ErrToken'] = 'El formulario ha caducado: recargue la página y vuelva a intentarlo.';
$lang['ErrAccess'] = 'Acceso denegado.';
$lang['ErrTimeZone'] = 'Zona horaria desconocida.';
$lang['ErrDate'] = 'fecha u hora no válida.';
$lang['ErrTimeGap'] = 'esta hora no existe en esta zona (cambio de hora).';
$lang['ErrEndBeforeStart'] = 'El fin debe ser posterior al inicio.';
$lang['ErrInterval'] = 'El intervalo debe estar entre {$a[min]} y {$a[max]} minutos.';
$lang['ErrPingUrl'] = 'La dirección de vigilancia debe ser una dirección web que empiece por https:// o http://.';
$lang['ErrDatesRequired'] = 'Una programación activa necesita un inicio y un fin.';
$lang['ErrEndPast'] = 'El fin ya ha pasado.';
$lang['ErrNoSession'] = 'Marque al menos una sesión de introducción, o desmarque la apertura y el cierre de la introducción.';
$lang['ErrRunArchery'] = 'Las competiciones de Run Archery no son compatibles con este módulo.';

$lang['SavedDisabled'] = 'Guardado. La programación está inactiva.';
$lang['SavedRunning'] = 'Guardado. El periodo ha empezado: la tarea programada actúa en menos de un minuto.';
$lang['SavedWaiting'] = 'Guardado. La tarea programada actuará al inicio del periodo.';

$lang['JsLoadError'] = 'No se pudo leer el estado.';
$lang['JsTask'] = 'Tarea';
$lang['JsSimulation'] = 'simulación';
$lang['JsLastSuccess'] = 'Último envío correcto:';
$lang['JsNever'] = 'nunca';
$lang['JsNextSend'] = 'Próximo envío:';
$lang['JsFailures'] = 'Fallos consecutivos:';
$lang['JsSessions'] = 'Introducción:';
$lang['JsOpen'] = 'abierta';
$lang['JsClosed'] = 'cerrada';
$lang['JsSendNow'] = 'Enviar ahora';
$lang['JsSendNowPending'] = 'Envío solicitado…';
$lang['JsSendNowHint'] = 'La tarea programada lo hace en menos de un minuto, imitado si la simulación está marcada.';
$lang['JsHistory'] = 'Historial';
$lang['JsNoRun'] = 'Todavía no se ha hecho nada.';
$lang['JsWhen'] = 'Cuándo';
$lang['JsKind'] = 'Acción';
$lang['JsResult'] = 'Resultado';
$lang['JsDuration'] = 'Duración';
$lang['JsSize'] = 'Tamaño';
$lang['JsDetail'] = 'Detalle';
$lang['JsOk'] = 'OK';
$lang['JsKo'] = 'fallo';
$lang['JsAll'] = 'todos';
$lang['JsNone'] = 'ninguno';

<?php
/**
 * Spanish strings of the live draw module. Keys missing here fall back to the
 * English file, which holds the reference list.
 */

// Module and menu
$lang['ModuleName'] = 'Sorteo en directo';
$lang['MenuShows'] = 'Sorteos';
$lang['MenuUpdate'] = 'Actualizar módulo';
$lang['UpdateTitle'] = 'Sorteo en directo — actualización del módulo';

// Home page
$lang['HomeLead'] = 'Las pantallas de un sorteo en público: una pantalla para la sala o la retransmisión, una regiduría para registrar cada equipo sorteado y una pantalla para los comentaristas. Un sorteo no pertenece a ninguna competición.';
$lang['ShowsTitle'] = 'Sorteos';
$lang['ShowsNone'] = 'Todavía no hay sorteos. Cree uno más abajo o importe una copia de la antigua página de sorteo.';
$lang['ColTitle'] = 'Sorteo';
$lang['ColProgress'] = 'Avance';
$lang['ColUpdated'] = 'Última modificación';
$lang['ColScreens'] = 'Pantallas';
$lang['Progress'] = '{$a[drawn]} de {$a[total]} puestos sorteados';
$lang['OpenControl'] = 'Regiduría';
$lang['OpenEdit'] = 'Parámetros y equipos';
$lang['OpenDisplay'] = 'Pantalla pública';
$lang['OpenSpeaker'] = 'Comentaristas';
$lang['Duplicate'] = 'Copiar';
$lang['DuplicateHint'] = 'Copia las listas, el historial, las notas y el aspecto en un nuevo sorteo sin puestos sorteados: así empieza el sorteo del año siguiente.';
$lang['ConfirmDeleteShow'] = '¿Eliminar el sorteo «{$a}» con sus listas, su historial y sus notas?';
$lang['Delete'] = 'Eliminar';
$lang['SourceNone'] = 'Sin competición de la temporada anterior';
$lang['CreateTitle'] = 'Nuevo sorteo';
$lang['FieldTitle'] = 'Título';
$lang['FieldTitlePlaceholder'] = 'Sorteo primera división 2027';
$lang['FieldSource'] = 'Competición de la temporada anterior';
$lang['FieldSourceHint'] = 'Opcional. La competición ianseo de la temporada recién jugada: se pueden importar sus equipos, y los comentaristas ven la temporada, los resultados y los arqueros de cada equipo.';
$lang['Create'] = 'Crear';
$lang['ImportTitle'] = 'Importar una copia de la antigua página de sorteo';
$lang['ImportLead'] = 'La página HTML independiente usada antes de este módulo exportaba su estado en un archivo JSON. Indique ese archivo, y la página misma si todavía la tiene: los nombres de las categorías y las listas completas de equipos solo están escritos en la página. Los puestos sorteados no se importan.';
$lang['ImportSave'] = 'Copia (.json)';
$lang['ImportPage'] = 'Antigua página (.html), opcional';
$lang['Import'] = 'Importar';
$lang['CopyOf'] = 'Copia de {$a}';
$lang['LegacyTitle'] = 'Sorteo importado';
$lang['LegacyCategory'] = 'Categoría {$a}';
$lang['EditTitle'] = 'Parámetros y equipos';
$lang['ControlTitle'] = 'Regiduría';
$lang['SpeakerTitle'] = 'Comentaristas';
$lang['BackToShows'] = 'Todos los sorteos';
$lang['Loading'] = 'Cargando…';

// Errors and messages
$lang['ErrToken'] = 'La sesión ha caducado. Vuelva a cargar la página e inténtelo de nuevo.';
$lang['ErrNameEmpty'] = 'El nombre es obligatorio.';
$lang['ErrNoShow'] = 'Este sorteo ya no existe.';
$lang['ErrNoCategory'] = 'Esta categoría ya no existe.';
$lang['ErrNoTeam'] = 'Este equipo ya no existe.';
$lang['ErrNoSource'] = 'Elija primero la competición de la temporada anterior.';
$lang['ErrField'] = 'Campo desconocido.';
$lang['ErrAction'] = 'Acción desconocida.';
$lang['ErrImage'] = 'El archivo no es una imagen aceptada (JPEG, PNG, WebP o GIF, 5 MB como máximo).';
$lang['ErrLink'] = 'Este enlace de pantalla no es válido. Quizá se ha renovado: pida el nuevo.';
$lang['ErrLegacyFormat'] = 'Este archivo no es una copia de la antigua página de sorteo.';
$lang['ErrLegacyEmpty'] = 'La copia no contiene ninguna categoría.';
$lang['ErrNetwork'] = 'El servidor no ha respondido. Compruebe la conexión e inténtelo de nuevo.';
$lang['ErrSeasonApplied'] = 'Esta temporada ya se ha añadido al historial.';
$lang['ErrNoEventChecked'] = 'Marque al menos una prueba.';
$lang['MsgImported'] = '{$a} equipo(s) importado(s).';
$lang['MsgLinked'] = '{$a} equipo(s) vinculado(s) a su club.';
$lang['MsgSeasonApplied'] = 'Historial actualizado para {$a[updated]} equipo(s); puesto anterior borrado para {$a[cleared]} equipo(s) ausente(s) de esa temporada.';

// Preparation page
$lang['SectionGeneral'] = 'Sorteo';
$lang['FieldSubtitle'] = 'Subtítulo';
$lang['LinksTitle'] = 'Enlaces de las pantallas';
$lang['LinksHint'] = 'Abra estos enlaces en los equipos que muestran las pantallas. No se necesita cuenta ianseo: quien tiene un enlace ve esa pantalla, así que dé el enlace de los comentaristas solo a los comentaristas.';
$lang['Copy'] = 'Copiar';
$lang['Copied'] = 'Enlace copiado.';
$lang['Open'] = 'Abrir';
$lang['NewTokens'] = 'Renovar los enlaces';
$lang['NewTokensHint'] = 'Los enlaces actuales dejan de funcionar de inmediato.';
$lang['ConfirmNewTokens'] = '¿Renovar ambos enlaces? Las pantallas ya abiertas dejarán de actualizarse hasta que se abran con los nuevos enlaces.';
$lang['SectionSeasonNone'] = 'Temporada anterior';
$lang['SeasonNoSource'] = 'Elija arriba la competición de la temporada anterior para importar sus equipos y dar a los comentaristas la temporada de cada equipo.';
$lang['SectionSeason'] = 'Temporada anterior — {$a}';
$lang['ImportEventsLead'] = 'Cada prueba por equipos marcada se convierte en una categoría con sus equipos, vinculados a su club. Una prueba ya presente solo recibe los equipos que le faltan.';
$lang['AlreadyListed'] = 'ya presente';
$lang['NoTeamEvent'] = 'Esta competición no tiene pruebas por equipos.';
$lang['ImportEvents'] = 'Importar los equipos';
$lang['LinkClubs'] = 'Vincular los equipos a los clubes';
$lang['LinkClubsHint'] = 'Para los equipos escritos o importados por su nombre: encuentra su club en la prueba vinculada comparando los nombres.';
$lang['ApplySeason'] = 'Añadir la temporada {$a} al historial';
$lang['ApplySeasonHint'] = 'Una sola vez: +1 participación por equipo de esa temporada, +1 victoria para el campeón, +1 podio para los tres primeros, puesto anterior sustituido por la clasificación final.';
$lang['SeasonAppliedPill'] = 'Temporada {$a} añadida al historial';
$lang['ConfirmApplySeason'] = '¿Añadir la temporada {$a} al historial de todos los equipos vinculados? Solo se puede hacer una vez.';
$lang['NationalOn'] = 'Clasificaciones nacionales disponibles: los comentaristas ven el puesto nacional de cada arquero.';
$lang['NationalOff'] = 'Clasificaciones nacionales no disponibles. Las descarga el módulo REPARTITION_EPREUVES.';
$lang['FieldName'] = 'Nombre';
$lang['FieldEvent'] = 'Prueba de la temporada anterior';
$lang['EventNone'] = 'Sin prueba vinculada';
$lang['TeamCount'] = '{$a} equipo(s)';
$lang['MoveUp'] = 'Subir';
$lang['MoveDown'] = 'Bajar';
$lang['DeleteCategory'] = 'Eliminar la categoría';
$lang['ConfirmDeleteCategory'] = '¿Eliminar esta categoría y todos sus equipos?';
$lang['ColTeam'] = 'Equipo';
$lang['ColClub'] = 'Código de club';
$lang['ColHint'] = 'Temporada anterior';
$lang['ColHintTitle'] = 'Clasificación final en la prueba vinculada de la competición de la temporada anterior';
$lang['ColNote'] = 'Nota para los comentaristas';
$lang['NoTeam'] = 'Ningún equipo en esta categoría.';
$lang['DeleteTeam'] = 'Eliminar el equipo';
$lang['ConfirmDeleteTeam'] = '¿Eliminar el equipo «{$a}»?';
$lang['AddTeams'] = 'Añadir equipos: uno por línea, seguido si hace falta de ; y del código de club';
$lang['AddTeamsPlaceholder'] = 'RIOM;0163157';
$lang['Add'] = 'Añadir';
$lang['AddCategory'] = 'Añadir una categoría';
$lang['AddCategoryHint'] = 'Una categoría es una lista propia: equipos cuyo orden se sortea, o las etapas de la temporada presentadas una a una a medida que el locutor las anuncia.';
$lang['SectionLook'] = 'Pantalla pública';
$lang['Preview'] = 'Vista previa';
$lang['LookBg'] = 'Color de fondo';
$lang['LookAccent'] = 'Color de acento';
$lang['LookText'] = 'Color del texto';
$lang['LookFontTitle'] = 'Fuente de los títulos';
$lang['LookFontBody'] = 'Fuente del texto';
$lang['LookSizeTitle'] = 'Tamaño del título';
$lang['LookSizeTeam'] = 'Tamaño de los nombres de equipo';
$lang['LookSizeRank'] = 'Tamaño de los números de puesto';
$lang['LookMargin'] = 'Márgenes superior e inferior';
$lang['LookOverlay'] = 'Velo sobre la imagen';
$lang['LookAmbient'] = 'Animación de fondo';
$lang['LookSlots'] = 'Mostrar los puestos por sortear';
$lang['LookImage'] = 'Imagen de fondo';
$lang['LookImageHint'] = 'JPEG, PNG, WebP o GIF, 5 MB como máximo. El velo, del color de fondo, mantiene legible el texto.';
$lang['ImageSet'] = 'Imagen colocada';
$lang['ImageNone'] = 'Sin imagen';
$lang['ImageClear'] = 'Quitar la imagen';

// History columns
$lang['StatParticipations'] = 'Participaciones';
$lang['StatWins'] = 'Victorias';
$lang['StatPodiums'] = 'Podios';
$lang['StatRank'] = 'Puesto anterior';
$lang['StatParticipationsShort'] = 'Part.';
$lang['StatWinsShort'] = 'Vict.';
$lang['StatPodiumsShort'] = 'Podios';
$lang['StatRankShort'] = 'Ant.';

// Control page
$lang['OnAir'] = 'En la pantalla pública';
$lang['SceneIdle'] = 'Espera';
$lang['SceneCategory'] = 'Categoría en curso';
$lang['SceneSummary'] = 'Resumen';
$lang['Categories'] = 'Categorías';
$lang['StatsShown'] = 'Historial mostrado';
$lang['UndoLast'] = 'Deshacer el último puesto';
$lang['ResetCategory'] = 'Reiniciar la categoría';
$lang['ConfirmReset'] = '¿Retirar todos los puestos sorteados en «{$a}»?';
$lang['SearchTeam'] = 'Buscar un equipo: Intro lo sortea cuando solo coincide uno';
$lang['NextPlace'] = 'Próximo puesto';
$lang['CategoryComplete'] = 'Todos los puestos están sorteados';
$lang['PlaceN'] = 'Puesto {$a}';
$lang['Unassign'] = 'Retirar este puesto';
$lang['NoMatch'] = 'Ningún equipo coincide.';
$lang['NoCategory'] = 'Este sorteo aún no tiene categorías: añádalas en la página de preparación.';
$lang['NothingDrawn'] = 'Todavía no se ha sorteado nada.';
$lang['DrawOrder'] = 'Orden sorteado';
$lang['LeftToDraw'] = 'Por sortear';
$lang['PreviewTitle'] = 'La pantalla pública ahora';

// Public screen
$lang['DrawKicker'] = 'Sorteo';
$lang['WaitingDraw'] = 'Esperando el sorteo…';

// Commentators' screen
$lang['SpeakerPick'] = 'Elija un equipo en las listas.';
$lang['NotDrawnYet'] = 'Aún no sorteado';
$lang['NewInEvent'] = 'Ausente de esta prueba en {$a}';
$lang['SeasonTitle'] = 'Temporada {$a}';
$lang['NotInEvent'] = 'Este equipo no participó en esta prueba en {$a[year]}.';
$lang['AlsoIn'] = 'El club también tenía un equipo en:';
$lang['FactRank'] = 'Clasificación final';
$lang['FactPoints'] = 'Puntos de clasificación';
$lang['FactMatches'] = 'Encuentros ganados–perdidos';
$lang['FactAvg'] = 'Puntos por flecha';
$lang['FactBest'] = 'Mejor encuentro, por flecha';
$lang['FactStreak'] = 'Racha de victorias más larga';
$lang['FactShootOffs'] = 'Desempates ganados–perdidos';
$lang['FactSets'] = 'Puntos de set a favor–en contra';
$lang['FactForm'] = 'Últimos encuentros:';
$lang['FactStage'] = 'Etapa';
$lang['FactQualification'] = 'Clasificatoria';
$lang['FactBonus'] = 'bonificación {$a}';
$lang['CompositionTitle'] = 'Arqueros en {$a}';
$lang['ColArcher'] = 'Arquero';
$lang['NationalOutdoor'] = 'Clasificación nacional aire libre {$a}';
$lang['NationalIndoor'] = 'Clasificación nacional sala {$a}';
$lang['SpeakerNoClub'] = 'Este equipo no tiene código de club: no se puede encontrar su temporada anterior. Añádalo en la página de preparación.';
$lang['Won'] = 'Ganado';
$lang['Lost'] = 'Perdido';
$lang['Ordinal1'] = '{$a}º';
$lang['OrdinalN'] = '{$a}º';

// Version 0.2.0: stages, list for the ianseo competition, medals, settings page
$lang['FieldType'] = 'Tipo de lista';
$lang['TypeTeams'] = 'Equipos por sortear';
$lang['TypeStages'] = 'Etapas presentadas una a una';
$lang['StageCount'] = '{$a} etapa(s)';
$lang['StageN'] = 'Etapa {$a}';
$lang['ColStageName'] = 'Lugar';
$lang['ColStageDetail'] = 'Fechas y detalles';
$lang['AddStages'] = 'Añadir etapas: una por línea, seguida si hace falta de ; y de las fechas';
$lang['AddStagesPlaceholder'] = 'Smarves;17 y 18 de abril de 2027';
$lang['StagesHint'] = 'Aquí no se sortea nada: la pantalla pública presenta las etapas en este orden, una tarjeta tras otra, a medida que el locutor las anuncia.';
$lang['NoStage'] = 'Ninguna etapa en esta lista.';
$lang['DeleteStage'] = 'Eliminar la etapa';
$lang['ShowStage'] = 'Mostrar';
$lang['ShowNextStage'] = 'Mostrar la etapa siguiente';
$lang['HideStage'] = 'Ocultar de nuevo esta etapa';
$lang['StagesShown'] = '{$a[shown]} de {$a[total]} etapas mostradas';
$lang['AllStagesShown'] = 'Todas las etapas están mostradas';
$lang['StagesList'] = 'Etapas';
$lang['StageOnScreen'] = 'en pantalla';
$lang['StageUpcoming'] = 'aún no mostrada';
$lang['PasteTitle'] = 'Lista para la competición ianseo';
$lang['PasteHint'] = 'Códigos de club en el orden sorteado. Péguelos en el cuadro de texto bajo la columna {$a} de la pantalla Setup de la competición de primera división de la nueva temporada.';
$lang['PasteHintNoEvent'] = 'Códigos de club en el orden sorteado. Péguelos en el cuadro de texto de la columna correspondiente de la pantalla Setup de la competición de primera división de la nueva temporada.';
$lang['PasteMissingCode'] = 'Sin código de club para: {$a}. Añádalo antes en los parámetros; sin él, todos los equipos siguientes subirían un puesto en ianseo.';
$lang['PasteIncomplete'] = 'De momento solo {$a[drawn]} de {$a[total]} puestos sorteados';
$lang['CopyList'] = 'Copiar la lista';
$lang['ListCopied'] = 'Lista copiada.';
$lang['MedalGold'] = 'Mejor valor de la categoría';
$lang['MedalSilver'] = 'Segundo valor de la categoría';
$lang['MedalBronze'] = 'Tercer valor de la categoría';
$lang['EditLead'] = 'Cada cambio se guarda en cuanto sale del campo: no hay ningún botón que pulsar. Vuelva aquí desde la lista de sorteos o desde la regiduría.';

// Version 0.2.1: commentators' screen
$lang['BackToLive'] = 'Volver al directo';

// Version 0.2.3: spacing inside a slot of the public screen
$lang['LookRankSpace'] = 'Espacio número → equipo';
$lang['LookStatsSpace'] = 'Espacio equipo → estadísticas';
$lang['LookStatGap'] = 'Espacio entre estadísticas';
$lang['LookStatWidth'] = 'Anchura de una estadística';

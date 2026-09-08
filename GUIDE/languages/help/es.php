<?php
/**
 * Spanish strings of the authoring documentation, the "help" section.
 *
 * Keys must mirror languages/help/en.php, which is the fallback. Only wording
 * here: the page's structure lives in admin/help.php.
 *
 * Translated without a native reviewer: worth a proofread.
 */

/* Contents and shared table headers */
$lang['HlpContents']        = 'Índice';
$lang['HlpColField']        = 'Campo';
$lang['HlpColPurpose']      = 'Función';
$lang['HlpColOption']       = 'Opción';
$lang['HlpColEffect']       = 'Efecto';
$lang['HlpColAchievement']  = 'Distinción';
$lang['HlpColEarnedBy']     = 'Se consigue';

/* 1. The idea */
$lang['HlpIdeaTitle']       = 'La idea';
$lang['HlpIdeaP1']          = 'Un <b>curso</b> es una secuencia de <b>pasos</b> que aparece en un panel lateral, sobre cualquier página de ianseo. Cada paso explica una acción, puede señalarla visualmente — botón resaltado, flecha, indicación — y luego espera a que la acción se haya realizado de verdad antes de desbloquear el paso siguiente.';
$lang['HlpIdeaP2']          = 'Todo se construye desde el editor visual (<b>Nuevo curso</b> o <b>Editar</b>): no hace falta escribir código. El JSON se genera solo y sigue disponible para los usuarios avanzados en la sección plegada «Código JSON».';

/* 2. The course */
$lang['HlpCourseTitle']     = 'El curso';
$lang['HlpCourseFTitle']    = 'Título';
$lang['HlpCourseVTitle']    = 'El nombre que aparece en el catálogo y en lo alto del panel.';
$lang['HlpCourseFDesc']     = 'Descripción';
$lang['HlpCourseVDesc']     = 'Una frase breve, mostrada bajo el título en el catálogo.';
$lang['HlpCourseFVersion']  = 'Versión';
$lang['HlpCourseVVersion']  = 'Un número libre, por ejemplo <code>1.0</code>. Si lo aumenta, los usuarios que habían terminado la versión anterior aparecen como «versión anterior».';
$lang['HlpCourseFThumb']    = 'Miniatura';
$lang['HlpCourseVThumb']    = 'Una imagen 16:9 opcional, mostrada en el catálogo para reconocer el curso de un vistazo.';
$lang['HlpCourseFId']       = 'ID';
$lang['HlpCourseVId']       = 'Un identificador único, generado automáticamente. No lo cambie una vez publicado el curso: es la clave con la que se guarda el progreso.';

/* 3. The step */
$lang['HlpStepTitle']       = 'El paso';
$lang['HlpStepIntro']       = 'Cada paso tiene:';
$lang['HlpStepLiTitle']     = 'un <b>título</b>;';
$lang['HlpStepLiContent']   = 'un <b>contenido</b>, texto con formato — véase §4;';
$lang['HlpStepLiImage']     = 'una <b>imagen</b> opcional — véase §5;';
$lang['HlpStepLiPage']      = 'una <b>página predeterminada</b> y sus <b>disparadores</b> — véase §6;';
$lang['HlpStepLiOptions']   = '<b>opciones</b>: opcional y no permisivo — véase §9.';
$lang['HlpStepOutro']       = 'Use <b>+ Antes</b> y <b>+ Después</b> para insertar pasos, y las flechas <b>◀ ▶</b> para moverse entre ellos. El panel de la izquierda es una <b>vista previa en directo</b>: lo que ve es exactamente lo que verá el usuario.';

/* 4. Writing the content */
$lang['HlpContentTitle']    = 'Escribir el contenido';
$lang['HlpContentIntro']    = 'La barra de herramientas sobre la vista previa da formato al texto:';
$lang['HlpContentLiFormat'] = '<b>negrita</b>, <i>cursiva</i>, <u>subrayado</u>, color del texto;';
$lang['HlpContentLiLists']  = 'listas con viñetas y numeradas;';
$lang['HlpContentLiTip']    = '<b>💡 Consejo</b> inserta un recuadro amarillo, para un consejo o una advertencia;';
$lang['HlpContentLiCode']   = '<b>&lt;/&gt;</b> pone el texto seleccionado en estilo «código» — útil para el nombre de un botón o una ruta.';
$lang['HlpContentTip']      = '<b>Dentro de un recuadro de consejo:</b> pulse <b>Mayús + Intro</b> para empezar una línea <i>dentro</i> del recuadro. Pulse <b>Intro</b> a solas para <i>salir</i> del recuadro y seguir con texto normal debajo.';

/* 5. Images */
$lang['HlpImagesTitle']     = 'Las imágenes';
$lang['HlpImagesLiOne']     = 'Como mucho una imagen por paso, más una miniatura para el curso.';
$lang['HlpImagesLiFormats'] = 'Se aceptan los formatos habituales, <b>GIF animados incluidos</b>.';
$lang['HlpImagesLiRatio']   = 'La imagen se muestra en <b>16:9</b>: otra proporción recibe simplemente <b>franjas negras</b>, y la imagen nunca se deforma ni se recorta.';
$lang['HlpImagesLiAbove']   = 'La imagen aparece <b>encima del texto</b> del paso.';
$lang['HlpImagesLiOptional']= 'Las imágenes son <b>opcionales</b>: sin imagen, el paso se muestra con normalidad.';
$lang['HlpImagesNote']      = 'Las imágenes van incrustadas en el propio curso, en base64. Evite archivos pesados — un GIF de varios megabytes vuelve lento el curso y su sincronización. El editor avisa a partir de 2 MB.';

/* 6. Triggers */
$lang['HlpTriggersTitle']   = 'Los disparadores';
$lang['HlpTriggersIntro']   = 'Un disparador describe lo que el usuario debe hacer para que el paso quede completo. Un paso puede contener varios y se activan <b>por orden</b> — arrastre para reordenarlos. Hay dos familias.';
$lang['HlpTrigActionTitle'] = 'Acción — el usuario hace algo';
$lang['HlpTrigFPage']       = 'Página';
$lang['HlpTrigVPage']       = 'La página donde ocurre la acción. Vacío = la página predeterminada del paso. <code>*</code> = <b>cualquier página</b>, que es lo que necesitan los menús, presentes en todas partes.';
$lang['HlpTrigFType']       = 'Tipo';
$lang['HlpTrigVType']       = 'El evento esperado: clic, doble clic, cambio, escritura, foco, paso del ratón, envío… <code>— ninguno</code> resalta el elemento y deja que el usuario confirme a mano.';
$lang['HlpTrigFSelector']   = 'Selector';
$lang['HlpTrigVSelector']   = 'El elemento, como selector CSS — <code>#btnSave</code>, <code>.menu-item</code>. Se resalta, con una flecha.';
$lang['HlpTrigFTooltip']    = 'Indicación';
$lang['HlpTrigVTooltip']    = 'Texto breve opcional mostrado junto al elemento resaltado.';
$lang['HlpTrigFRequired']   = 'Oblig.';
$lang['HlpTrigVRequired']   = 'Si se marca, el paso no se completa hasta que la acción se haya hecho.';
$lang['HlpTrigStateTitle']  = 'Estado — esperar a que una condición se cumpla';
$lang['HlpTrigStateP1']     = 'En lugar de una acción, el paso espera a que se cumpla una <b>condición</b> dentro de ianseo — por ejemplo «hay una competición abierta». Mientras no sea así, un mensaje lo indica. La condición se vuelve a comprobar cada vez que el usuario regresa al paso.';
$lang['HlpTrigStateP2']     = 'Siempre hay una condición integrada disponible: <b>📍 Página actual</b>. Comprueba que el usuario está en una página dada, indicada en el campo que aparece. Esa página <b>puede ser distinta</b> de la del paso. Hasta que el usuario llegue, el paso sigue bloqueado y se muestra el enlace «Ir a la página →».';
$lang['HlpTrigStateP3']     = 'La otra condición integrada es <b>🔎 Presencia o ausencia de un elemento</b>. Se indica un <b>selector CSS</b> — los selectores por prefijo como <code>[id^="…"]</code> también valen aquí — y se dice si el elemento debe estar <b>presente</b> o <b>ausente</b>. Útil para esperar un mensaje de confirmación, la apertura de una ventana o la desaparición de un indicador de carga. Esta condición se <b>vuelve a comprobar automáticamente</b>, porque un elemento así puede aparecer o desaparecer sin que la página se recargue.';
$lang['HlpTrigStarNote']    = '<b>La página <code>*</code></b> funciona tanto como página predeterminada del paso como en cada disparador por separado. Un disparador puesto en <code>*</code> sigue activo sea cual sea la página en pantalla.';
$lang['HlpBranchTitle']     = 'Ramas condicionales (⎇ Activo si…)';
$lang['HlpBranchIntro']     = 'Todo disparador, de acción o de estado, lleva una condición de activación <b>⎇</b>. De forma predeterminada un disparador está <b>siempre activo</b>. Puede hacerse condicional:';
$lang['HlpBranchLiIf']      = '<b>si</b> se cumple una condición — «si: hay una competición abierta»;';
$lang['HlpBranchLiIfNot']   = '<b>si NO</b> una condición — «si NO: hay una competición abierta».';
$lang['HlpBranchP']         = 'Un disparador cuya condición no se cumple se <b>omite</b>, y la secuencia pasa al siguiente. Así un curso puede seguir <b>caminos distintos</b> según el estado de ianseo y luego volver a una secuencia común:';
$lang['HlpBranchTip']       = 'Por ejemplo: «si NO: hay una competición abierta» en un disparador que guía la creación de una; los disparadores posteriores, <b>sin condición</b>, son comunes a los dos casos. Para un «si no», use dos disparadores — uno <i>si X</i>, el otro <i>si NO X</i>.';

/* 7. Finding a CSS selector */
$lang['HlpSelectorsTitle']  = 'Encontrar un selector CSS';
$lang['HlpSelIntro']        = 'Para apuntar a un elemento — botón, campo, enlace:';
$lang['HlpSelLiInspect']    = 'En la página en cuestión, haga <b>clic derecho</b> en el elemento y elija <b>Inspeccionar</b> (o pulse <b>F12</b>).';
$lang['HlpSelLiId']         = 'Busque su <code>id</code>, por ejemplo <code>id="btnSave"</code>: el selector es <code>#btnSave</code>.';
$lang['HlpSelLiClass']      = 'Sin <code>id</code>, use una clase — <code>class="btn-primary"</code> da <code>.btn-primary</code>.';
$lang['HlpSelLiStable']     = 'Prefiera siempre algo <b>estable y único</b> en la página.';
$lang['HlpSelTip']          = 'En el inspector, clic derecho en la línea del elemento y luego <b>Copiar</b> → <b>Copiar selector</b>.';
$lang['HlpSelDynTitle']     = 'Los id numerados, que cambian cada vez';
$lang['HlpSelDynP1']        = 'Algunos id de ianseo llevan un número de registro que cambia con el participante o con la competición, como <code>#d_q_QuSession_25360</code> o <code>#d_QuD1Score_25360</code>. Un selector exacto funcionaría una sola vez. Use un <b>selector por prefijo</b>:';
$lang['HlpSelDynLiPrefix']  = '<code>[id^="d_q_QuSession_"]</code> — un id que <b>empieza por</b> este prefijo;';
$lang['HlpSelDynLiOther']   = '<code>[id$="_sufijo"]</code> — termina por; <code>[id*="parte"]</code> — contiene.';
$lang['HlpSelDynP2']        = 'El disparador se activa entonces con <b>cualquier</b> elemento coincidente — la casilla Tanda de un participante cualquiera, por ejemplo — y la flecha señala el primero encontrado. El <b>grabador de disparadores</b> reconoce por sí solo estos id numerados y escribe el selector por prefijo en su lugar.';

/* 8. Recording triggers */
$lang['HlpRecordTitle']     = 'Grabar los disparadores automáticamente';
$lang['HlpRecIntro']        = 'En vez de escribir los selectores a mano, <b>🔴 Grabar disparadores</b>, en las opciones del paso, los captura haciendo clic directamente en ianseo:';
$lang['HlpRecLiSaved']      = 'El curso se <b>guarda primero</b> y luego se le lleva a la página del paso (o a la página de inicio).';
$lang['HlpRecLiPanel']      = 'Aparece un <b>panel rojo</b>. <b>Cada clic</b> que haga en ianseo se graba como disparador, y el clic sigue funcionando con normalidad — puede moverse entre páginas y la grabación continúa.';
$lang['HlpRecLiPage']       = '<b>📍 Página actual</b> añade un disparador de estado que comprueba la presencia en la página en la que está.';
$lang['HlpRecLiPause']      = '<b>⏸ Pausa</b> suspende la captura, para poder hacer clic sin grabar. <b>↶ Deshacer</b> quita el último disparador.';
$lang['HlpRecLiFinish']     = '<b>✓ Terminar</b> vuelve al editor y añade lo capturado. <b>✕</b> lo descarta.';
$lang['HlpRecTip']          = 'Después, <b>repase los disparadores</b> — tipo, indicación, obligatorio — ajuste lo necesario y <b>guarde el curso</b>. Los selectores generados son sólidos, pero no infalibles en elementos muy dinámicos.';

/* 9. Step options */
$lang['HlpOptionsTitle']    = 'Opciones del paso';
$lang['HlpOptFOptional']    = 'Opcional';
$lang['HlpOptVOptional']    = 'Muestra un botón «Marcar como hecho»: el usuario puede completar el paso sin realizar la acción.';
$lang['HlpOptFStrict']      = 'No permisivo';
$lang['HlpOptVStrict']      = 'Bloquea todo clic <b>fuera</b> del elemento esperado, de modo que el usuario solo puede interactuar con el objetivo. El panel parpadea en rojo cuando se bloquea un clic.';

/* 10. Quiz and challenge */
$lang['HlpActivitiesTitle'] = 'Cuestionario y reto — bronce, plata y oro';
$lang['HlpActIntro']        = 'Un curso puede ofrecer hasta <b>tres actividades</b>: la <b>guía</b> paso a paso, un <b>cuestionario</b> y un <b>reto</b>. Al terminar la guía se invita al usuario a seguir con el cuestionario, luego el reto y luego el curso siguiente. Cada actividad superada eleva la distinción:';
$lang['HlpActBronze']       = 'Terminar la guía';
$lang['HlpActSilver']       = 'La guía más otra actividad';
$lang['HlpActGold']         = 'Todas las actividades que ofrece el curso';
$lang['HlpActLiQuiz']       = '<b>Cuestionario</b> (sección «📝 Cuestionario» del editor): preguntas con dos a cuatro opciones, <b>una o varias</b> respuestas correctas marcadas — el usuario debe entonces seleccionar exactamente el conjunto correcto — una explicación opcional, una nota mínima (70 % de forma predeterminada) y una opción para mostrar las respuestas en <b>orden aleatorio</b>.';
$lang['HlpActLiChallenge']  = '<b>Reto</b> (sección «🎯 Reto»): un enunciado y <b>condiciones de estado</b> que comprueban el resultado dentro de ianseo, con el usuario trabajando sin ninguna ayuda. Las condiciones se crean en el <b>constructor de condiciones</b> (el botón ⚡ de la administración), que sabe probarlas en directo sobre la competición abierta.';
$lang['HlpActShipped']      = 'Las condiciones que acompañan al módulo cubren el desarrollo habitual de una competición:';
$lang['HlpActLiState']      = '<b>al menos un participante inscrito</b>, <b>al menos un juez declarado</b>, <b>al menos una diana asignada</b>, <b>al menos una puntuación introducida</b> — todas comprobadas sobre la competición abierta;';
$lang['HlpActLiVisited']    = '<b>se ha visitado una página dada</b> — la comprobación «<b>Página visitada</b>» del constructor. Una visita se recuerda <b>por usuario y por competición</b>; marque «cualquier competición» para una visita global. Ideal para un reto del tipo «envíe sus resultados a la federación».';

/* 11. Learning path and the other content types */
$lang['HlpPathTitle']       = 'Itinerario, listas de comprobación, resolución de problemas, ayuda contextual';
$lang['HlpPathLiPath']      = '<b>Itinerario</b>: los campos <b>Grupo</b>, <b>Subgrupo</b> y <b>Orden</b> organizan el catálogo en secciones ordenadas. El «curso siguiente» propuesto al final sigue ese orden.';
$lang['HlpPathLiChecklist'] = '<b>Listas de comprobación</b>: unas cuantas preguntas con botones y luego una lista de tareas adaptada a las respuestas. Los elementos con una condición se marcan solos. Se crean con «+ Lista de comprobación» (edición JSON).';
$lang['HlpPathLiTrouble']   = '<b>Resolución de problemas</b>: un árbol de pregunta → respuestas → solución. Se crea con «+ Resolución de problemas» (edición JSON).';
$lang['HlpPathLiContext']   = '<b>Ayuda contextual</b>: activada de forma predeterminada. El botón flotante 🎯 muestra un punto naranja cuando existe contenido para la página de ianseo en pantalla. Puede desactivarse desde el catálogo o desde el panel.';

/* 12. User accounts */
$lang['HlpAccountsTitle']   = 'Cuentas de usuario (servidor compartido)';
$lang['HlpAccountsP']       = 'Cuando el servidor usa un módulo de cuentas, <b>cada cuenta tiene su propio progreso</b>: cursos en marcha, pasos completados, cuestionarios, retos y distinciones se guardan por usuario, sin afectar a los demás. El cartel «Aprenda a usar ianseo» aparece para una cuenta que no ve <b>ninguna competición</b>, señal de un organizador nuevo. Los <b>cursos</b> en sí siguen siendo comunes a todo el servidor. Sin módulo de cuentas nada cambia: el progreso es sencillamente el de la instalación.';

/* 13. Translating a course */
$lang['HlpTranslateTitle']  = 'Traducir un curso';
$lang['HlpTransP1']         = 'Un curso lleva todos los idiomas en los que se ha escrito <b>dentro de su propio archivo</b>: sigue siendo un solo documento con un solo número de versión, y el mecanismo de actualización tiene una sola cosa que comparar.';
$lang['HlpTransLiBase']     = '<b>Idioma original</b> indica en qué idioma se escribió el curso — el idioma de todos los campos aún no traducidos. El idioma de edición no puede responder a eso: dice dónde está escribiendo ahora, no qué es ya el texto existente. Sin él, la primera traducción de un campo archivaría el texto original bajo el idioma que estaba escribiendo, y el curso llevaría su original como traducción. Su valor predeterminado es su propio idioma y solo conviene cambiarlo si retoma un curso escrito por otra persona.';
$lang['HlpTransLiEdit']     = '<b>Idioma de edición</b> elige qué idioma muestran y escriben los campos. Cambiarlo no toca los demás idiomas.';
$lang['HlpTransLiEmpty']    = 'Un campo aún sin traducir aparece <b>vacío</b>, no con el texto original — así se ve de un vistazo lo que falta.';
$lang['HlpTransNote']       = 'Solo se traduce el <b>texto</b> de un campo. La estructura que lo rodea — pasos, disparadores, selectores, páginas — nunca se duplica, de modo que los idiomas no pueden separarse. Un curso cuya versión traducida señalara un botón distinto del original sería peor que un curso sin traducción alguna.';
$lang['HlpTransP2']         = 'El lector obtiene el curso en el idioma de su interfaz. En su defecto, el inglés; en su defecto, el idioma en que se escribió el curso — así, un curso traducido a un solo idioma se sirve igualmente y nunca se muestra vacío. Un idioma regional cuenta por el camino como su idioma padre: un lector en francés canadiense obtiene el texto francés antes de que se considere el inglés.';

<?php
/**
* <b>lang_cback_ctracker.php</b><br><br>
* Spanish Language File for the CBACK Cracker Tracker
*
* @author Christian Knerr (cback)
* @translator Raul Arroyo (ThE KuKa) - http://www.phpbb-es.com
* @package ctracker
* @version 5.0.3
* @since 22.10.2006 - 16:40:28
* @copyright (c) 2006 www.cback.de
*
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*/


/*
 * Language Strings used for the ACP Menu points
 */
$lang['ctracker_module_category'] 		    = 'CrackerTracker';
$lang['ctracker_module_1']                = 'Explorar Comprobacion';
$lang['ctracker_module_2']                = 'Creditos';
$lang['ctracker_module_3']                = 'Explorar Archivos';
$lang['ctracker_module_4']                = 'Noticias Globales';
$lang['ctracker_module_5']                = 'Bloquear IP y Agente';
$lang['ctracker_module_6']                = 'Administrar Log';
$lang['ctracker_module_7']                = 'Mantenimiento y Pruebas';
$lang['ctracker_module_8']                = 'Usuario Miserable';
$lang['ctracker_module_9']                = 'Ajustes';
$lang['ctracker_module_10']               = 'Recuperación';
$lang['ctracker_module_11']               = 'Pie de Página';


/*
 * Language Strings used in ACP Modules itself
 */
$lang['ctracker_wrong_module']			  = 'Numero de Modulo desconocido';
$lang['ctracker_img_descriptions']	  = 'Imagen';
$lang['ctracker_set_catname1']			  = 'Bloquear IP, Proxy y Agente de Usuario';
$lang['ctracker_set_catname2']			  = 'Sistema de protección de busqueda';
$lang['ctracker_set_catname3']			  = 'Sistema de protección de identificación';
$lang['ctracker_set_catname4']			  = 'Detección de Auto Spam';
$lang['ctracker_set_catname5']			  = 'Sistema de protección del registro';
$lang['ctracker_set_catname6']			  = 'Comprobar Contraseña	';
$lang['ctracker_set_catname7']			  = 'Características generales de Seguridad';
$lang['ctracker_set_catname8']			  = 'Otros Ajustes';
$lang['ctracker_settings_head']       = 'Ajustes de CrackerTracker';
$lang['ctracker_settings_expl']       = 'Aquí puede modificar todos los ajustes para requisitos generales del sistema de seguridad de CBACK CrackerTracker.';
$lang['ctracker_button_submit']			  = 'Guardar Ajustes';
$lang['ctracker_button_reset']			  = 'Restaurar';

$lang['ctracker_settings_m1']	  		  = 'Activar Bloquear IP';
$lang['ctracker_settings_e1']		  	  = 'Ajustar el Bloquear IP, Proxy y Agente de Usuario en Encendido o Apagado.';
$lang['ctracker_settings_m2']			    = 'Tamaño del Log de Bloquear IP';
$lang['ctracker_settings_e2']			    = 'Aquí puede fijar el numero de entradas para el archivo del Log de Bloquear IP. Si el numero de entradas supera el limite, el archivo del Log será borrado automáticamente para ahorrar espacio Web.';
$lang['ctracker_settings_m3']	  		  = 'Activar protección de Busqueda';
$lang['ctracker_settings_e3']		  	  = 'Aquí puede fijar en Encendido o Apagado el Sistema de Protección de Búsqueda.';
$lang['ctracker_settings_m4']			    = 'Tiempo de búsqueda para los usuarios';
$lang['ctracker_settings_e4']			    = 'Este es el tiempo de espera (en segundos) para usuarios registrados hasta que puedan volver a realizar una búsqueda, si activa la Protección de Búsqueda.';
$lang['ctracker_settings_m5']			    = 'Numero de Búsquedas para los usuarios';
$lang['ctracker_settings_e5']			    = 'Aquí puede ajustar el numero de consultas, eso se puede lograr en el intervalo del tiempo indicado arriba para los usuarios registrados. Si el numero supera el limite, otras consultas serán bloqueadas por el tiempo mostrado arriba para reducir la carga del servidor.';
$lang['ctracker_settings_m6']  			  = 'Tiempo de búsqueda para los invitados';
$lang['ctracker_settings_e6']		  	  = 'Periodo de tiempo (en segundos) que los invitados deben esperar, si el Sistema de Protección de Búsqueda esta activado.';
$lang['ctracker_settings_m7']			    = 'Numero de Búsquedas para los invitados';
$lang['ctracker_settings_e7']			    = 'Aquí puede ajustar cuantas búsquedas, en un periodo de tiempo especificado tiene permitido un invitado. Si el numero supera el limite, otras consultas serán bloqueadas por el tiempo mostrado arriba para reducir la carga del servidor.';
$lang['ctracker_settings_m8']	  		  = 'Ajustar en Encendido la Protección de Identificación';
$lang['ctracker_settings_e8']		  	  = 'Aquí puede activar o desactivar el Sistema de Protección de Identificación de CrackerTracker.';
$lang['ctracker_settings_m9']			    = 'Tamaño del Log para identificaciones erróneas';
$lang['ctracker_settings_e9']			    = 'Aquí puede ajustar cuantas entradas de identificaciones erróneas deben ser guardadas antes de ser borrado automáticamente para ahorrar especio Web.';
$lang['ctracker_settings_m10']			  = 'Numero de identificaciones hasta la Confirmación Visual';
$lang['ctracker_settings_e10']			  = 'Cuantas veces un usuario puede fallar en su identificación antes de la protección de Ataques de Fuerza Bruta, donde se mostrara la Confirmación Visual.';
$lang['ctracker_settings_m11']			  = 'Historial de Identificación';
$lang['ctracker_settings_e11']			  = 'Aquí puede activar o desactivar el Historial de Identificación de usuarios.';
$lang['ctracker_settings_m12']			  = 'Entradas en el Historial de Idenficación por usuario';
$lang['ctracker_settings_e12']			  = 'Aquí puede ajustar cuantas identificaciones correctas por usuario deben ser guardadas en el Historial de Identificación. Cada usuario tiene la opción de comprobar la fecha y las direcciones IP de sus identificaciones.';
$lang['ctracker_settings_m13']			  = 'Característica de Identificación de IP';
$lang['ctracker_settings_e13']			  = 'Activar o Desactivar el Sistema de Identificación de IP. Cada usuario tiene la opción de activar o desactivar la página del Sistema de Seguridad de Identificación. El Sistema de Protección de IP comprueba los cambios de las direcciones IP. El usuario será informado si su rango de IP a sido modificado desde su ultima identificación. Aquí vera si alguien ha entrado de una localización diferente.';
$lang['ctracker_settings_m14']			  = 'Detección de Spammer';
$lang['ctracker_settings_e14']			  = 'Aquí el modo de Detección Automática de Spammer puede ajustarse.';
$lang['ctracker_settings_m15']			  = 'Periodo de tiempo para el Spammer';
$lang['ctracker_settings_e15']			  = 'Periodo de tiempo en el cual los mensajes de los usuarios serán contados por el Detector de Spammer. (en Segundos)';
$lang['ctracker_settings_m16']			  = 'Numero de Mensaje de Spammer';
$lang['ctracker_settings_e16']			  = 'Numero de mensajes permitido en el periodo de tiempo ajustado. Si este numero se supera el usuario será identificado como un Spammer.';
$lang['ctracker_settings_m17']			  = 'Tamaño del Log de Spammer';
$lang['ctracker_settings_e17']			  = 'Tamaño del Log en el cual los usuarios identificados como Spammer serán guardados.';
$lang['ctracker_settings_m18']			  = 'Protección del Registro';
$lang['ctracker_settings_e18']			  = 'Aquí puede activar o desactivar la Protección del Registro.';
$lang['ctracker_settings_m19']			  = 'Tiempo de bloque para el Registro';
$lang['ctracker_settings_e19']			  = 'Aquí puede ajustar el tiempo entre dos registros. (en segundos)';

$lang['ctracker_settings_m21']			  = 'Vigilante de IP';
$lang['ctracker_settings_e21']			  = 'Si se activa esta característica, un usuario con una dirección IP idéntica podrá registrarse, hasta que alguien se haya registrado con otra dirección IP.';
$lang['ctracker_settings_m22']			  = 'Validez de Contraseña';
$lang['ctracker_settings_e22']			  = 'Activar la comprobación de Validez de Contraseña para todos los usuarios.';
$lang['ctracker_settings_m23']			  = 'Validez de Contraseña en días';
$lang['ctracker_settings_e23']			  = 'Cuánto tiempo la contraseña del usuario será válida, antes de que se muestre una nota de que la contraseña debe ser cambiada. (en días)';
$lang['ctracker_settings_m24']			  = 'Comprobar la complejidad de la contraseña';
$lang['ctracker_settings_e24']			  = 'Esta característica comprueba la complejidad de las contraseñas de los usuarios.';
$lang['ctracker_settings_m25']			  = 'Modo de Contraseña Compleja';
$lang['ctracker_settings_e25']			  = 'Aquí puede ajustar, si tiene que haber INDICACIÓN en las contraseñas.';
$lang['ctracker_settings_m26']			  = 'Tamaño mínimo de la Contraseña';
$lang['ctracker_settings_e26']			  = 'Aquí puede ajustar el numero mínimo de letras para la contraseña.';
$lang['ctracker_settings_m27']			  = 'Comprobador del reajuste de Contraseña';
$lang['ctracker_settings_e27']			  = 'Permite para reajustar una contraseña desde un cierto período de tiempo (para los usuarios). Esto previene, que los atacantes no puedan utilizar esta característica a los usuarios Spammers que usan Resetmails.';
$lang['ctracker_settings_m28']			  = 'Periodo de tiempo de reajuste de Contraseña';
$lang['ctracker_settings_e28']			  = 'Periodo de tiempo que los usuarios pueden reajustar su contraseña (en minutos)';
$lang['ctracker_settings_m29']			  = 'Monitorizar el Correo';
$lang['ctracker_settings_e29']			  = 'Aquí puede activar esta característica, así los usuarios pueden utilizar la función de correo del foro, solamente una vez en el período de tiempo. Esto previene el Spamming.';
$lang['ctracker_settings_m30']			  = 'Tiempo de E-mail Spam';
$lang['ctracker_settings_e30']			  = 'Periodo de tiempo en el que dos E-Mails de usuarios pueden enviar usando la función interna de correo (en minutos)';
$lang['ctracker_settings_m31']			  = 'Auto Recuperación';
$lang['ctracker_settings_e31']			  = 'Activa esta característica para guardar los ajustes del foro automáticamente. Si esto no funciona, puede utilizar la última configuración.';
$lang['ctracker_settings_m32']			  = 'Confirmación Visual para Invitados';
$lang['ctracker_settings_e32']			  = 'Cuando se activa esta caracteristica para los invitados, estos tienen que introducir un código visual para escribir nuevos mensajes. De no ser así, no podrán enviar el mensaje. Esto nos protege contra los Spambots automáticos.';
$lang['ctracker_settings_m33']			  = 'Protección del Servicio Disponible-Correo';
$lang['ctracker_settings_e33']			  = 'CrackerTracker tiene una lista interna de servicios de Disponible-Correo supuesto. Si activa esta característica, los usuarios con tales direcciones de correo no podrán registrarse.';
$lang['ctracker_settings_m34']			  = 'Identificación de Configuración incorrecta';
$lang['ctracker_settings_e34']			  = 'Cuando activa esta característica, CrackerTracker comprueba los ajustes generales de phpBB y su validez. ¡No puede dañar a su foro por la configuración!';
$lang['ctracker_settings_m35']			  = 'Detección de Spammer';
$lang['ctracker_settings_e35']			  = 'Cuando activa esta característica, CrackerTracker observara los Spammers humanos o Spamposts. La mayor parte de serán bloqueados.';
$lang['ctracker_settings_m36']			  = 'Comprobar palabras clave de Spammer';
$lang['ctracker_settings_e36']			  = 'Cuando activa "Detección de Spammer", las palabras claves en Perfil y los mensajes serán explorados para identificar Spammers.<br /><br /><b>ATENCIÓN</b> Aquí existe un riesgo más alto de la detección de algún BUG para los nuevos usuarios. Por favor compruebe el fichero Log para saber si hay detección del Spammer.';


$lang['ctracker_settings_on']			  = 'Activar';
$lang['ctracker_settings_off']			= 'Desactivar';
$lang['ctracker_blockmode_0']			  = 'Apagado';
$lang['ctracker_blockmode_1']			  = 'Banear Usuario';
$lang['ctracker_blockmode_2']			  = 'Bloquear Usuario';
$lang['ctracker_complex_1']				  = '[0-9]';
$lang['ctracker_complex_2']				  = '[a-z]';
$lang['ctracker_complex_3']				  = '[A-Z]';
$lang['ctracker_complex_4']				  = '[0-9][a-z]';
$lang['ctracker_complex_5']				  = '[0-9][A-Z]';
$lang['ctracker_complex_6']				  = '[0-9][a-z][A-Z]';
$lang['ctracker_complex_7']				  = '[0-9][*]';
$lang['ctracker_complex_8']				  = '[0-9][a-z][*]';
$lang['ctracker_complex_9']				  = '[0-9][a-z][A-Z][*]';


/*
 * Credits page in ACP
 */
$lang['ctracker_credits_head']			  = 'Creditos';
$lang['ctracker_credits_subhead']     = 'Aquí están los Créditos de CBACK CrackerTracker. Aquí le daremos más información sobre seguridad y esto es también una manera de decir "Gracias".';
$lang['ctracker_credits_donate']      = 'Donar';
$lang['ctracker_credits_donate_expl'] = '¿Quiere <b>CBACK CrackerTracker Profesional</b>? Entonces seria estupendo, que usted hiciera una donación al Proyecto de CBACK usando la donación de PayPal. El desarrollo adicional y los costes del servidor, no ayudan a nuestro proyecto no lucrativo. Así podremos seguir proporcionar CrackerTracker gratuitamente en el futuro.<br /><br />Muchas gracias por su soporte.';
$lang['ctracker_credits_credits']		  = 'Creditos';
$lang['ctracker_credits_credits_1']		= 'Idea e Implementación';
$lang['ctracker_credits_credits_2']		= 'Autor y Soporte';
$lang['ctracker_credits_credits_3']		= 'Iconos';
$lang['ctracker_credits_credits_4']		= 'Sitio de Descarga Oficial';
$lang['ctracker_credits_moddownload']	= 'Descargar CrackerTracker MOD';
$lang['ctracker_credits_thanks']		  = 'Gracias a...';
$lang['ctracker_credits_thanks_text']	= 'Quisiera darle las gracias a las siguientes personas:';
$lang['ctracker_credits_thanks_to']		= '<b>Ideas, pruebas de seguridad y correcciones</b><br />Tekin Birdüzen<br /><i>(<a href="http://www.cybercosmonaut.de" target="_blank">cYbercOsmOnauT</a>)</i><br /><br /><br /><br /><b>Ideas:</b><br />Bernhard Jaud<br /><i>(GenuineParts)</i><br /><br /><br /><br /><b>Traductor (Ingles)</b><br />Marc Renninger<br /><i>(mc-dragon)</i><br /><br /><br /><br /><b>Corrector (Ingles)</b><br />George <br />Sommerset<br /><i>(<a href="http://www.englisch-hilfen.de" target="_blank">www.englisch-hilfen.de</a>)</i><br /><br /><br /><br /><b>Beta Tester</b><br />Gracias a todos los participantes de Beta-Tests<br />a los usuarios de CBACK Premium y por supuesto a<br />nuestros colegas de la "Escena-Mod" quienes también nos ayudaron en Beta Tests y correcciones.</i>';
$lang['ctracker_credits_info']			  = '¿Mas Seguridad?';
$lang['ctracker_credits_info_text']		= 'El MOD perfecto para phpBB y CrackerTracker: Para una optima seguridad recomendamos el MOD <b>Advanced Visual Confirmation</b> por AmigaLink. Este MOD mejora la característica CAPTCHA de phpBB y CrackerTracker Profesional con un sistema más complejo que no pueden leer los Robots. Este MOD puede descargarlo en <a href="http://www.amigalink.de" target="_blank">www.AmigaLink.de</a>.<br /><br /><br /><br />Sugerimos que también integre este MOD en su Foro para una seguridad excelente.';


/*
 * File Hash Check in ACP
 */
$lang['ctracker_fchk_head']				    = 'Comprobador Explorador CrackerTracker';
$lang['ctracker_fchk_subhead']			  = 'Esta exploración crea una comprobación de cada uno de los archivos PHP de su foro con un clic en "Crear o Actualizar Comprobador". Después, siempre tiene la posibilidad con “Verificar cambios del archivo” para determinar, si los archivos fueron cambiados o no desde su última comprobación. Puede ver si los archivos fueron cambiados sin nada aparente. Esto es generalmente una muestra de que alguien había accedido a su volumen de datos del foro. ¡Preste atención a la última vez que lo comprobó, para ver si una persona no autorizada activó el explorador de comprobación!<br /><br /><br /><b>Información:</b> No todos los servidores soportan esta característica. Ocasionalmente esto puede ocasionar fuera de tiempo, si el servidor toma demasiado tiempo para producir la lista del archivo de phpBB. Otros servidores paran el procedimiento puesto que es un funcionamiento absolutamente intensivo.<br /><br /><br />&raquo; La última actualización del archivo de comprobación ocurrieron <b>%s</b>.';
$lang['ctracker_fchk_funcheader']		  = 'Caracteristicas';
$lang['ctracker_fchk_tableheader']		= 'Sistema de Salida';
$lang['ctracker_fchk_option1']			  = 'Crear o Actualizar Comprobador';
$lang['ctracker_fchk_option2']			  = 'Verificar cambios del archivo';
$lang['ctracker_fchk_select_action']	= '¡Por favor elija una opción!';
$lang['ctracker_fchk_update_action']	= '¡Comprobador actualizado!';
$lang['ctracker_fchk_tablehead1']		  = 'Ruta del archivo';
$lang['ctracker_fchk_tablehead2']		  = 'Estado';
$lang['ctracker_file_unchanged']		  = 'NO MODIFICADO';
$lang['ctracker_file_changed']		 	  = 'MODIFICADO';
$lang['ctracker_file_deleted']        = 'BORRADO';


/*
 * File Safety Scanner in ACP
 */
$lang['ctracker_fscan_complete']		  = 'El explorador de archivos a sido ejecutado con éxito. Por favor haga clic en "Mostrar Resultados", para ver los resultados. Usted puede corregir los archivos.<br /><br /><br /><br /><u>AVISO:</u><br /><br />Puede suceder de vez en cuando que CrackerTracker detecta un archivo como inseguro. Esto puede suceder como los archivos de PHP pueden estar muy, muy diferente y un desarrollador desea a veces que el código pueda ser escrito desde el exterior. En este caso - y SOLAMENTE si ser absolutamente seguro puedes decir CRACKERTRACKER que éste es un archivo seguro. Debe escribir en este archivo, al principio: <?php el siguiente código: <br /><br /><br /><i>// CTracker_Ignore: File Checked By Human</i><br /><br /><br />Si usted no esta seguro puede mirar en la <a href="http://www.community.cback.de" target="_blank">Comunidad CBACK</a> para más instrucciones.';
$lang['ctracker_fscan_unchecked']		  = 'NO COMPROBADO';
$lang['ctracker_fscan_ok']            = 'SEGURO';
$lang['ctracker_fscan_prob_1']			  = 'extension.inc no/incluido demasiado tarde';
$lang['ctracker_fscan_prob_2']			  = '$phpbb_root_path quizá no se inicializo correctamente';
$lang['ctracker_fscan_prob_3']			  = 'common.php / pagestart.php quizá no fue incluido o demasiado tarde';
$lang['ctracker_fscan_prob_4']			  = 'El código en el archivo es posiblemente ejecutable más allá de phpBB';
$lang['ctracker_fscan_prob_5']			  = 'extension.inc falta y/o $phpbb_root_path y/o constante no se encontró';
$lang['ctracker_fscan_prob_def']		  = 'Un caso indefinido ocurrió durante la exploración';
$lang['ctracker_fscan_important']		  = '¡Por favor ponga Atención!';
$lang['ctracker_fscan_sel_action']	  = 'Para comenzar a comprobación de todos los archivos, por favor haga clic en "Comenzar la Comprobación de Archivos". Cuando esto termine, haga clic en "Mostrar Resultados" para mostrar los resultados de la comprobación. Esta lista se puede recuperar en cualquier momento usando el ACP hasta que una nueva comprobación sea comenzada.<br /><br /><br />Por razones técnicas no es posible dar <u>inequívoco</u> y <u>erróneo</u> información acerca de la seguridad de PHP Script. No estar demasiado seguro. Puede suceder, eso que el explorador clasifica un archivo seguro, como inseguro y viceversa. PHP es así de complejo y también los códigos - Así será un tanto por ciento seguro. En esto no habrá más escrituras inseguras. ;-)<br /><br /><br />Este explorador se especializa para detectar los agujeros de seguridad en ficheros incluidos. Con este explorador puede encontrar fácilmente estos riesgos y corregirlos.<br /><br /><br />¡Para más detalles e instrucciones use la Comunidad CBACK!<br /><br /><br />';
$lang['ctracker_fscan_head']			    = 'CBACK CrackerTracker Security Scanner';
$lang['ctracker_fscan_subhead']			  = 'Este explorador de seguridad comprueba todos los archivos de PHP de tu foro, con problemas importantes de modo que no haya agujeros de seguridad que podrían ser exploits de Worms. Estos agujeros se pueden utilizar desde exterior sin usar los archivos del Foro. Si el sistema de CrackerTracker esta inactivo no puede proteger el archivo. Con el módulo del ACP haces que la opción específica detecte los agujeros y los corrija.<br /><br /><br /><b>Por favor, nota:</b> ¡No todos los servidores soportan esta característica! Para los Foros muy grandes puede suceder que este funcionamiento intensivo de Sistema de Exploración, sobrepase el tiempo de ejecución de PHP. El algoritmo de este explorador estaba en su haber optimizado lo más mejor posible, ese esto está refrenando en límites, no obstante puede ocurrir tristemente en algunas máquinas. We plead to consider this.<br /><br /><br />&raquo; La última comprobación esta en <b>%s</b>.';
$lang['ctracker_fscan_option1']			  = 'Comenzar la Comprobación de Archivos';
$lang['ctracker_fscan_option2']			  = 'Mostrar Resultados';


/*
 * Global message in ACP
 */
$lang['ctracker_glob_msg_head']			  = 'Mensaje Global';
$lang['ctracker_glob_msg_subhead']	  = 'Aquí puede dejar un Mensaje Global a todos los usuarios. Este mensaje que el usuario verá en la siguiente identificación. Tiene la opción a referirse en un hilo o para escribir su propio texto (255 caracteres). ;)'; 
$lang['ctracker_glob_msg_entry']      = 'Fijar Mensaje Global ';
$lang['ctracker_glob_msg_submit']		  = 'Insertar';
$lang['ctracker_glob_msg_reset']		  = 'Cancelar Mensaje';
$lang['ctracker_glob_msg_type']			  = 'Escriba el Mensaje Global';
$lang['ctracker_glob_type_1']			    = 'Texto';
$lang['ctracker_glob_type_2']		  	  = 'Enlace';
$lang['ctracker_glob_msg_txt']			  = 'Texto del Mensaje Global';
$lang['ctracker_glob_msg_link']			  = 'Enlace de destino en el Mensaje';
$lang['ctracker_glob_msg_reset']		  = 'Cancelar mensaje actual';
$lang['ctracker_glob_res_txt']			  = 'Si usted hace clic en "Cancelar mensaje actual" el mensaje guardado sera cancelado.';
$lang['ctracker_glob_msg_saved']		  = 'El Mensaje Global a sido guardado.<br /><br />Clic <a href="%s">Aquí</a> para volver a la Administración de CrackerTracker.';
$lang['ctracker_glob_msg_reset_ok']	  = 'El Mensaje Global a sido borrado de la tabla del suaurio. El mensaje incorporado no será mostrado más.<br /><br />Clic <a href="%s">Aquí</a> para volver a la Administración CrackerTracker.';
$lang['ctracker_dbg_mode']            = '<b>CrackerTracker funcionando en MODO DEBUG. Esto no debe ser una condición permanente.<br />Por favor ajuste de nuevo a modo normal cuanto antes.<br /><br /><u>¡Este Mensaje no puede ser borrado!</u></b>';

/*
 * IP&Agent Blocker
 */
$lang['ctracker_ipb_delete']			  = 'Borrar Entrada';
$lang['ctracker_ipb_blocklist']		  = 'Lista de entradas bloqueadas';
$lang['ctracker_ipb_head']          = 'Bloquear Proxy, IP y Agente de Usuario';
$lang['ctracker_ipb_description']	  = 'Aquí puede administrar la lista de Bloqueos de CrackerTracker Proxy, IP y Agente de Usuario Bloqueados. Usted puede borrar las entradas existentes y añadir otras nuevas. Con una nueva entrada tiene la opción de utilizar (*) para introducir cualquier combinación fuera del filtro en la lista. Por ejemplo: lwp* bloquea lwp-1 así como lwp-simple etc. o 100.*.*.* bloquea todas las direcciones IP que empiecen por 100.<br /><br /><b>ATENCIÓN</b> Observe que no bloque su propio Agente de Usuario o dirección IP. ¡Si no estará fuera de su Foro!';
$lang['ctracker_ipb_new_entry']		  = 'Nueva Entrada';
$lang['ctracker_ipb_added']         = '¡Entrada añadida correctamente!';
$lang['ctracker_ipb_deleted']			  = '¡Entrada borrada correctamente!';
$lang['ctracker_ipb_add_now']			  = 'Añadir Entrada';


/*
 * Log Manager
 */
$lang['ctracker_log_manager_title']		    = 'Administrar Archivo Log';
$lang['ctracker_log_manager_subtitle']    = 'Aquí puede mostrar o borrar todos los archivos del Log de CrackerTracker.';
$lang['ctracker_log_manager_overview']    = 'Administrar Descripción del Log';
$lang['ctracker_log_manager_blocked']	    = 'CrackerTracker ha bloqueado <b>%s</b> ataques hasta ahora.';
$lang['ctracker_log_manager_overview']    = 'Descripción del Archivo Log';
$lang['ctracker_log_manager_head1']		    = 'Nombre del Log';
$lang['ctracker_log_manager_head2']		    = 'Numero de Entradas';
$lang['ctracker_log_manager_head3']		    = 'Caracteristicas';
$lang['ctracker_log_manager_name2']		    = 'Protección de Worm y Exploit';
$lang['ctracker_log_manager_name3']	  	  = 'Bloquear IP, Proxy y Agente de Usuario';
$lang['ctracker_log_manager_name4']		    = 'Identificaciones Incorrectas';
$lang['ctracker_log_manager_name5']		    = 'Spammers Bloqueados';
$lang['ctracker_log_manager_name6']       = 'Entradas Debug';
$lang['ctracker_log_manager_view']		    = 'VER';
$lang['ctracker_log_manager_delete']	    = 'BORRAR';
$lang['ctracker_log_manager_delete_all']  = 'Borrar todo el archivo Log';
$lang['ctracker_log_manager_deleted']	    = '¡El archivo Log a sido borrado con exito!';
$lang['ctracker_log_manager_all_deleted'] = '¡Todos los archivos Log han sido borrados con exito!';
$lang['ctracker_log_manager_showheader1'] = 'Hay <b>una</b> entrada en este archivo Log. Clic <b><a href="%s">Aquí</a></b> para volver a Descripción del Archivo Log.';
$lang['ctracker_log_manager_showheader']  = 'Hay <b>%s</b> entradas en este archivo Log.<br />Clic <b><a href="%s">Aquí</a></b> para volver a Descripción del Archivo Log.';
$lang['ctracker_log_manager_showlog']	    = 'Ver archivo Log';
$lang['ctracker_log_manager_cell_1']	    = 'Fecha / Hora';
$lang['ctracker_log_manager_cell_2a']	    = 'Appeal';
$lang['ctracker_log_manager_cell_2b']  	  = 'Nombre';
$lang['ctracker_log_manager_cell_3']  	  = 'Referido';
$lang['ctracker_log_manager_cell_4']	    = 'Agente de Usuario';
$lang['ctracker_log_manager_cell_5']	    = 'Dirección IP';
$lang['ctracker_log_manager_cell_6']	    = 'Host Remoto';
$lang['ctracker_log_manager_sysmsg']	    = 'La última limpieza del archivo Log fue el <b>%s</b>.';


/*
 * Footer configuration
 */
$lang['ctracker_footer_head']			  = 'Administrar Pie de Pagina';
$lang['ctracker_footer_subhead']	  = 'Aquí puede elegir que Pie de Pagina de CrackerTracker quiere mostrar en su Foro. ¡Por favor no cambie del pie de pagina el enlace a www.cback.de!';
$lang['ctracker_select_footer']			= 'Elegir Pie de Pagina';
$lang['ctracker_footer_saveit']			= 'Aceptar la disposición del pie de pagina';
$lang['ctracker_footer_done']			  = '¡Cambios en el pie de pagina sido guardado correctamente!';


/*
 * Maintenance Module in ACP
 */
$lang['ctracker_ma_unknown']			  = '<font color="#FFB900"><b>DESCONOCIDO</b></font>';
$lang['ctracker_ma_secure']		  	  = '<font color="#1CBF00"><b>SEGURO</b></font>';
$lang['ctracker_ma_warning']			  = '<font color="#FF0000"><b>CUIDADO</b></font>';
$lang['ctracker_ma_active']		  	  = '<font color="#1CBF00"><b>ACTIVO</b></font>';
$lang['ctracker_ma_inactive']			  = '<font color="#FF0000"><b>NO ACTIVO</b></font>';
$lang['ctracker_ma_on']				  	  = 'ENCENDIDO';
$lang['ctracker_ma_off']		  		  = 'APAGADO';
$lang['ctracker_ma_ca']				  	  = '<font color="#1CBF00"><b>BIEN</b></font>';
$lang['ctracker_ma_ci']		  			  = '<font color="#FF0000"><b>NO FIJADO</b></font>';
$lang['ctracker_ma_head']				    = 'Comprobador de Mantenimiento y Sistema';
$lang['ctracker_ma_subhead']			  = 'Este sistema examina automáticamente los módulos de seguridad de CrackerTracker de características y demostraciones, para así optimizar tu sistema.';
$lang['ctracker_ma_systest']			  = 'Automatic System Test';
$lang['ctracker_ma_sectest']			  = 'Prueba de Seguridad';
$lang['ctracker_ma_maint']				  = 'Función de Servicio';
$lang['ctracker_ma_name_1']				  = 'Sistema de Protección de Worm y Exploit';
$lang['ctracker_ma_name_2']				  = 'Unidad de Control de Variable';
$lang['ctracker_ma_name_3']				  = 'Unidad de Protección de IP, Proxy y Agente de Usuario';
$lang['ctracker_ma_name_4']			    = 'Worm Hornada de las definiciones de la heurística - Número de definiciones: <b>%s</b>';
$lang['ctracker_ma_syshead_1']		  = 'Modulo de Seguridad';
$lang['ctracker_ma_syshead_2']		  = 'Estado';
$lang['ctracker_ma_seccheck_1']		  = 'Punto de Comprobación';
$lang['ctracker_ma_seccheck_2']		  = 'Versión / Estado';
$lang['ctracker_ma_seccheck_3']		  = 'Referencia';
$lang['ctracker_ma_seccheck_4']		  = 'Estado';
$lang['ctracker_ma_scheck_1']			  = 'Versión PHP (<a href="http://www.php.net" target="_blank">Visita el Sitio Web</a>)';
$lang['ctracker_ma_scheck_2']			  = '&raquo; PHP MODO SEGURO';
$lang['ctracker_ma_scheck_3']			  = '&raquo; PHP GLOBALES';
$lang['ctracker_ma_scheck_4']			  = 'Versión phpBB (<a href="http://www.phpbb.com" target="_blank">Visita el Sitio Web</a>)';
$lang['ctracker_ma_scheck_4a']		  = '&raquo; Confirmación Visual';
$lang['ctracker_ma_scheck_4b']		  = '&raquo; Activación de Cuenta';
$lang['ctracker_ma_scheck_5']			  = 'CBACK CrackerTracker (<a href="http://www.cback.de" target="_blank">Visita el Sitio Web</a>)';
$lang['ctracker_ma_chmod']				  = '<b>Estado de CHMOD777:</b> ';
$lang['ctracker_ma_desc_link']		  = 'EJECUTAR AHORA';
$lang['ctracker_ma_desc1']				  = '<b>Limpiar la tabla de IP, Proxy y Agente de Usuario</b><br />Aquí puede borrar <u>todas</u> las entradas de la tabla de IP, Proxy y Agente de Usuario.';
$lang['ctracker_ma_desc2']				  = '<b>Ajuste de Fábrica: Bloquear IP, Proxy y Agente de Usuario</b><br />Aquí puede restaurar el estado de entrega de los datos de la base de datos de IP, Proxy y Agente de Usuario. ¡Se perderán sus filtros!';
$lang['ctracker_ma_desc3']				  = '<b>Borrar Historial de Identificación</b><br />Aquí puede borrar todas las entradas del Historial de Identificación, cueste lo que cueste el usuario y el número ajustado se guarda cueste lo que cueste por usuario.';
$lang['ctracker_ma_desc4']				  = '<b>Limpiar Archivo-Tabla de Comprobación</b><br />Aquí puede borrar todas las entradas guardadas de la tabla de Archivo-Comprobación.';
$lang['ctracker_ma_desc5']				  = '<b>Limpiar la Tabla de Exploración de Seguridad</b><br />Aquí puede borrar todos los resultados	que fueron almacenados durante la examinación de seguridad de archivo en la base de datos.';
$lang['ctracker_ma_succ_main']		  = '¡Proceso ejecutado correctamente!';
$lang['ctracker_ma_err_main']			  = '¡Proceso ejecutado correctamente!';


/*
 * Miserable User Module in ACP...
 */
$lang['ctracker_mu_success']			  = 'El Usuario a sido marcado como "Usuario Miserable" y tendra algunos problemas practicando surf en tu foro inmediatamente. ;)';
$lang['ctracker_mu_error_admin']	  = '¡ADMINISTRADORES o MODERADORES no pueden ser marcados como Usuario Miserable!';
$lang['ctracker_mu_deleted']			  = 'Los usuarios elegidos han sido borrados con éxito de la lista de Usuario Miserable.';
$lang['ctracker_mu_head']				    = 'Usuario Miserable';
$lang['ctracker_mu_subhead']			  = 'Digamos que un Usuario no se comporta bien, y se ha registrado con otra cuenta diferente después de ser expulsado. Hay una característica llamada "Usuario Miserable", que se solicito con frecuencia. El sistema de CrackerTracker no compara el “que solucionamos mensajes de error desrazonables”, que es fácilmente visto o al menos se entiende que es una duda razonable, simplemente procedamos según el principio de "No alimentar al Mono": Un Usuario marcado como "Usuario Miserable", y sus mensajes solo podrán ser leídos por los Administradores. Para otros Usuarios, las contribuciones serán invisibles, por lo tanto nadie tiene que ocuparse del problema o apuro, y conseguirá que el mismo se aburra y acabara dejando el Foro.<br /><b>Nota: <u>Esta función nos permite hacer desaparecer los mensajes dentro de un hilo.</u> Usando "Citar" o "Buscar" todavía te muestra los mensajes del "Usuario Miserable"!';
$lang['ctracker_mu_select']				  = 'Marcar a un Usuario como Usuario Miserable';
$lang['ctracker_mu_find']				    = 'Buscar los Usuario(s)';
$lang['ctracker_mu_send']		  		  = 'Introducir nombres de Usuario';
$lang['ctracker_mu_entr']			  	  = 'Usuario(s) Marcado(s)';
$lang['ctracker_mu_uname']				  = 'Usuario(s) Introducido(s)';
$lang['ctracker_mu_remove']				  = 'Borrar Entradas';
$lang['ctracker_mu_no_defined']		  = 'No hay Usuarios marcados como "Usuario Miserable" por ahora.';


/*
 * Recovery feature in ACP
 */
$lang['ctracker_rec_head']				  = 'Recuperación del Sistema';
$lang['ctracker_rec_subhead']			  = 'Aquí puede realizar una copia de seguridad de la tabla de configuración de su Foro o puede ir a la última configuración que funciono. Si ha activado esta característica en los ajustes generales de CrackerTracker, se realizara una copia de seguridad cada vez que cambie los ajustes generales. (¡ATENCIÓN! ¡Esto <b>NO</b> es una copia de seguridad completa de la base de datos!)<br /><br />Cuando no esta en el ACP después de que haya cambiado ajustes, puede reactivar la última configuración que funciono usando la consola de la emergencia de CrackerTracker. Por favor lea el comentario del archivo <i>ctracker/emergency.php</i> para más instrucciones de las configuraciones del foro en una emergencia. Por favor mire que ese archivo, tiene que ser habilitado antes de usarlo.<br /><br /><b>¡PELIGRO!</b> ¡Esta característica se debe utilizar solamente con problemas serios!';
$lang['ctracker_rec_last_saved']		= 'Última copia de seguridad de la Tabla de Configuración: <b>%s</b>';
$lang['ctracker_rec_never_saved']		= '¡La tabla de configuración no se ha copiado por ahora!';
$lang['ctracker_rec_backup']			  = 'Copia de Seguridad de la Tabla de Configuración';
$lang['ctracker_rec_restore']			  = 'Recuperar la última tabla de configuración que funciono';
$lang['ctracker_rec_succ']				  = 'El proceso en la base de datos se ejecuto con exito.';
$lang['ctracker_rec_pab']				    = '¡La recuperación no esta disponible, antes usted debe crear una copia de seguridad con éxito!';


/*
 * Language Strings used at multiple places
 */
$lang['ctracker_error_updating_userdata'] = 'CBACK CrackerTracker no puede funcionar la operación de la base de datos en la tabla del usuario.';
$lang['ctracker_error_database_op']       = 'CBACK CrackerTracker no puede funcionar la operación de la base de datos correctamente.';
$lang['ctracker_message_dialog_title']    = 'CBACK CrackerTracker Profesional';


/*
 * Language Strings used for the footer itself
 */
$lang['ctracker_fdisplay_imgdesc']  = 'Seguridad del Foro';
$lang['ctracker_fdisplay_n'] 			  = '<a href="http://www.cback.de" target="_blank">Seguridad</a> por <a href="http://www.cback.de" target="_blank">CBACK CrackerTracker</a>.';
$lang['ctracker_fdisplay_c'] 			  = 'Protegido por <a href="http://www.cback.de" target="_blank">CBACK CrackerTracker</a><br /><b>%s</b> Ataques Bloqueados.';
$lang['ctracker_fdisplay_g'] 			  = '<b>%s</b> Ataques Bloqueados';


/*
 * Language Strings for the class_ct_database.php
 */
$lang['ctracker_error_loading_config']    = 'La configuración de CBACK CrackerTracker no puede ser cargada de su base de datos. ¿Se a asegurado, de haber ejecutado el script de instalación y haber editado el archivo "includes/constants.php" correctamente?';
$lang['ctracker_error_updating_config']   = 'La configuración de CBACK CrackerTracker no puede ser actualizado. ¿Se a asegurado, de haber ejecutado el script de instalación y haber editado el archivo "includes/constants.php" correctamente?';
$lang['ctracker_error_loading_blocklist'] = 'La configuración de CBACK CrackerTracker no puede ser cargada de su base de datos. ¿Se a asegurado, de haber ejecutado el script de instalación y haber editado el archivo "includes/constants.php" correctamente?';
$lang['ctracker_error_insert_blocklist']  = 'Los datos no se han podido agregar a CBACK CrackerTracker Blocklist. ¿Se a asegurado, de haber ejecutado el script de instalación y haber editado el archivo "includes/constants.php" correctamente?';
$lang['ctracker_error_delete_blocklist']  = 'Los datos no se han podido borrar de CBACK CrackerTracker Blocklist. ¿Se a asegurado, de haber ejecutado el script de instalación y haber editado el archivo "includes/constants.php" correctamente?';
$lang['ctracker_error_login_history']     = 'Ha habido un error con las operaciones de la base de datos dentro de el Historial de Identificación de CBACK CrackerTracker. ¿Se a asegurado, de haber ejecutado el script de instalación y haber editado el archivo "includes/constants.php" correctamente?';
$lang['ctracker_error_del_login_history'] = 'La tabla de Historial de Identificación de CBACK CrackerTracker no puede vaciarse.';


/*
 * Language Strings used in class_ct_userfunctions.php
 */
$lang['ctracker_info_search_time']        = "Por razones de seguridad, la búsqueda solamente es posible en tiempos de %s cada %s segundos. Si este número fue excedido, deberá esperar <span id=\"waittime\">%s</span> segundos, hasta que pueda poner la siguiente ejecución de busqueda. <script type=\"text/javascript\"><!-- \n var wait = %s; var waitt = wait * 1000; for(i=1; i <= wait; i++) { window.setTimeout(\"newoutput(\" + i + \")\", i * 1000); } function newoutput(waitcounter) { if ( (waitt/1000) == waitcounter ) { document.getElementById(\"waittime\").innerHTML = \"0\"; } else { document.getElementById(\"waittime\").innerHTML = (waitt/1000) - waitcounter; } } //--></script>";
$lang['ctracker_info_regist_time']        = "Por razones de seguridad, solamente es posible un registro cada %s segundos. Si este número fue excedido, deberá esperar <span id=\"waittime\">%s</span> segundos, hasta que pueda poner la siguiente ejecución de busqueda de registros. <script type=\"text/javascript\"><!-- \n var wait = %s; var waitt = wait * 1000; for(i=1; i <= wait; i++) { window.setTimeout(\"newoutput(\" + i + \")\", i * 1000); } function newoutput(waitcounter) { if ( (waitt/1000) == waitcounter ) { document.getElementById(\"waittime\").innerHTML = \"0\"; } else { document.getElementById(\"waittime\").innerHTML = (waitt/1000) - waitcounter; } } //--></script>";
$lang['ctracker_info_regip_double']		    = 'Ha habido un registro desde esta dirección IP. Por razones de seguridad solamente se permite un registro desde una dirección IP.';
$lang['ctracker_info_profile_spammer']	  = '¡Este registro fue identificado como cuenta de Spam! Si piensa que esto es incorrecto, por favor mire de contactar con un Administrador de este Foro, de modo que él pueda comprobar su cuenta.';
$lang['ctracker_info_password_minlng']    = 'El Administrador a fijado, que la contraseña debe contener como mínimo <b>%s</b> caracteres. Su contraseña elegida tiene solamente <b>%s</b> caracteres. Por favor haga clic en "Volver" a introducir la nueva contraseña.';
$lang['ctracker_info_password_cmplx']	    = 'El Administrador a fijado, que la contraseña debe contener como <b>mínimo</b> las siguientes cosas: %s';
$lang['ctracker_info_password_cmplx_1']	  = 'Figuras';
$lang['ctracker_info_password_cmplx_2']	  = 'Minúscula';
$lang['ctracker_info_password_cmplx_3']	  = 'Mayúscula';
$lang['ctracker_info_password_cmplx_4']	  = 'Caracteres Especiales';
$lang['ctracker_info_pw_expired']		      = "El Administrador a creado unos ajustes para que su contraseña sea valida solamente por <b>%s días</b>. Le recomiendo por razones de seguridad que cambie su contraseña ahora. (<a href='profile.$phpEx?mode=editprofile&u=%d'>Perfil</a>)";


/*
 * Language Strings used in ct_visual_confirm.php
 */
$lang['ctracker_login_wrong']   = '¡El código de Confirmación Visual es incorrecto!';
$lang['ctracker_code_dbconn']   = '¡No se a podido cargar el código de confirmación visual en la base de datos! Si tiene phpBB Plus tiene que instalar el módulo de Confirmación Visual para phpBB. ¡Por favor lea las referencias para phpBB Plus en la carpeta "add_ons" del paquete de CrackerTracker Mod!';
$lang['ctracker_login_success'] = 'Su cuenta a sido activada de nuevo.<br /><br />Haga clic <a href="%s">Aquí</a> para volver a identificarse.';
$lang['ctracker_code_count']    = 'El numero de entradas de la Confirmación Visual a superado el limite para esta sesión.';


/*
 * Language Strings used in ctracker_login.php
 */
$lang['ctracker_login_title']   = 'Activación de Cuenta de CrackerTracker';
$lang['ctracker_login_logged']  = 'Los Usuarios identificados no pueden tener acceso al sitio.';
$lang['ctracker_login_confim']  = 'El número ajustado de las identificaciones incorrectas para tu cuenta fue alcanzado. Por lo tanto tu cuenta se ha bloqueado. Tendrá que ser reactivada usando la Confirmación Visual.<br /><br />Por favor introduzca el siguiente código y haga clic en "Desbloquear" para desbloquear su cuenta. Si hace esto, podrá abrir una sesión de nuevo.';
$lang['ctracker_login_button']  = 'Activar';


/*
 * Language Strings for IP Warning Engine
 */
$lang['ctracker_ipwarn_info']	= 'La exploración del Rango de IP de su cuenta es <b>%s</b>';
$lang['ctracker_ipwarn_prof']	= 'Explorador de Rango de IP';
$lang['ctracker_ipwarn_pdes']	= 'El explorador de rango de IP comprueba, si esta activado, la gama de IP y los supuestos cambios. Si alguien entra a su cuenta desde otra localización conseguirá un mensaje corto (también si entran desde diversas localizaciones). Si alguien entra a su cuenta desde otra localización conseguirás un mensaje corto (también si entran de diversas localizaciones). Por favor, compruebe el pie de página para ver si la característica amonestadora todavía se activa. Un atacante podría desactivar esto. Si la identificación sigue siendo activa, todavía tiene la capacidad de realizar cambios después de la activación.';
$lang['ctracker_ipwarn_chng']	= '<b>&raquo; CONSEJO &laquo;</b><br />El rango de IP de su cuenta ha cambiado. La actual identificación fue desde <b>%s</b>, la anterior fue desde <b>%s</b>. ¡Si no te abriste una sesión en otra localización, puede ser un agresor ha utilizado tu cuenta sin autorización!';
$lang['ctracker_ipwarn_welc']	= '<b>&raquo; CONSEJO &laquo;</b><br />El explorador del rango de IP para su cuenta no se ha inicializado todavía. Esto sucede después de dos conexiones. Si quiere ahora puede inicializar el explorador, después abrir una sesión y salir de la sesión.';
$lang['ctracker_ipwarn_send']	= 'Aceptar Ajustes';


/*
 * Language Strings for Login History
 */
$lang['ctracker_lhistory_h']	  = 'Historial de Identificación';
$lang['ctracker_lhistory_i']	  = 'Aquí puede ver las direcciones IP guardadas y la fecha de su ultima identificación <b>%s</b> conectándose. Usted puede si su cuenta fue usado por algún otro. Si hay Identificación/Fecha desconocidos o direcciones IP en el Historial de Identificación, es posible que alguien haya conseguido su contraseña. En este caso debe cambiar la contraseña de su cuenta y comprobar su cuenta de correo.';
$lang['ctracker_lhistory_h1']	  = 'Fecha y hora de identificación';
$lang['ctracker_lhistory_h2']  	= 'Dirección IP guardada';
$lang['ctracker_lhistory_nav']	= 'Historial de Identificación de CrackerTracker';
$lang['ctracker_lhistory_err']  = 'Usted debe identificarse usando las características de CrackerTracker.';
$lang['ctracker_lhistory_off']  = 'Historial de Identificación a sido desactivada por un Administrador.';


/*
 * Other Language Strings used in the Board itself
 */
$lang['ctracker_gmb_link']	  	= 'Un Administrador a escrito una nota importante para todos los usuarios. Esta nota puede ser enviada desde aquí:<br /><br /><a href="%s">%s</a><br />';
$lang['ctracker_gmb_mark']		  = 'Marcar mensaje como leído';
$lang['ctracker_gmb_markip']	  = 'Borrar Aviso';
$lang['ctracker_gmb_loginlink']	= 'Seguridad de Identificación';
$lang['ctracker_gmb_1stadmin']	= 'La instalación o ajustes del primer Administrador no pueden ser cambiadas.';
$lang['ctracker_gmb_pu_1']	  	= '<b>CBACK CrackerTracker - Configuración</b><br /><br />El puerto 21 es usado para servicios FTP. Si el foro es reconvertido a este puerto, el foro es normalmente más ejecutable, la causa es que los navegadores usan este puerto para el FTP.';
$lang['ctracker_gmb_pu_2']  		= '<b>CBACK CrackerTracker - Configuración</b><br /><br />¡La duración de sesión tiene un tamaño insuficiente! Quizás así, siempre no este identificado en el Foro el tiempo suficiente, antes de corregir este ajuste.';
$lang['ctracker_gmb_pu_3']	  	= '<b>CBACK CrackerTracker - Configuración</b><br /><br />¡La ruta del foro comienza y/o finaliza con una barra (/www/) o no solo se compone con la barra (/)!';
$lang['ctracker_gmb_pu_4']		  = '<b>CBACK CrackerTracker - Configuración</b><br /><br />¡El nombre del servidor no termina con barra (/)!';
$lang['ctracker_binf_spammer']	= 'El Sistema de Seguridad de Spam le esta observando. Usted a alcanzado el numero máximo de mensajes en %s segundos. Si usted intenta escribir en los próximos <b>%s</b> segundos otro mensaje, su cuenta será <b>¡Bloqueada!</b><br /><br />Por favor espere. Perdone por robarle su tiempo, pero es necesario por razones de seguridad.';
$lang['ctracker_binf_sban']		  = 'El Sistema de Bloque de Spam ha bloqueado su cuenta, ya que este le ha identificado como un Spammer.';
$lang['ctracker_sendmail_info'] = 'Debido a razones de seguridad usted solamente tiene permitido a enviar un e-mail cada %s minutos.';
$lang['ctracker_pwreset_info']	= 'Debido a razones de seguridad no es posible enviar una nueva contraseña cada %s minutos. ¡Por favor contacte con un Administrador si tiene prisa!';
$lang['ctracker_vc_guest_post'] = 'Confirmación Visual para Invitados';
$lang['ctracker_vc_guest_expl'] = 'Por favor introduzca el siguiente código antes de enviar su mensaje. Para los invitados es necesario por razones de seguridad por Spam.';

?>
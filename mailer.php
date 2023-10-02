<?php
/*

    SCRIPT PARA ENVIAR REMINDERS A LOS USUARIOS INACTIVOS DE MASTODON.
                                  By Trankten
                                        https://tkz.one/@trankten

*/

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer-6.8.0/src/Exception.php';
require 'PHPMailer-6.8.0/src/PHPMailer.php';
require 'PHPMailer-6.8.0/src/SMTP.php';

/* Dependencias:
   php-pgsql https://www.php.net/manual/en/book.pgsql.php
   
   - Debian/Ubuntu:
     apt install php-pgsql
   - cPanel:
     yum install php82-php-pgsql
*/


/* MODIFICAR ESTAS VARIABLES A CONTINUACIÓN */

// Lee detenidamente las instrucciones de uso.

// Configuración de la DB, se pueden sacar del .env.production.
// Si estás usando Docker asegurate de exponer el puerto del container al host.
// Si necesitas contraseña para conectar

$DB_HOST = "localhost";
$DB_PORT = "5432";
$DB_NAME = "";
$DB_USER = "";
$DB_PASS = "";

// Configuracion del servidor de SMTP para PHP Mailer
$EMAIL_HOST = "smtp-relay.gmail.com";
$EMAIL_AUTH = false;
$EMAIL_PORT = 587;
$EMAIL_ENC  = PHPMailer::ENCRYPTION_STARTTLS; // STARTTLS para 587
$EMAIL_USER = "";
$EMAIL_PASS = "";
$EMAIL_FROM = "avisos@tkz.one";

// Otra configuración.
$AWAY = 3; // Numero de meses que debe llevar sin logear como mínimo.
$DEBUG_MAIL = "test@trankten.com"; // Si pones un email aqui, se enviaran aqui en lugar del destinatario. Usalo para probar y dejalo vacio cuando hayas terminado.
$INSTANCIA = "TKZ.One";

// Una vez hayas configurado todo esto, puedes personalizar los correos modificando
// los ficheros "mail.html" y "mail.txt" (HTML y Plain Text respectivamente).
// Ahí puedes usar "{NOMBRE}, {USUARIO}, {FECHA}" para personalizar el correo.
//
// Una vez todo listo, ejecutalo via consola con:
//
// php mailer.php
// 
// ¡Asegurate de tener PHP-cli instalado! 
// Cada vez que ejecutes el programa enviará un email a una persona distinta.
// Puedes crear un cronjob para enviar un email cada X minutos y así
// evitar saturar el servidor de email, controlar el envio y evitar ser marcado como SPAM.
//
// Cada ejecución mostrará los registros de todos los que ya han sido enviados, así como el 
// último que será el que se envie y toda la conversación con el servidor SMTP.
// 
//  - Trankten
//
// Version 1.0
//



/* NO MODIFIQUES NADA DE AQUI ABAJO O PODRIA FALLAR TODO EL CHIRINGUITO! */





$conexion = pg_pconnect("host=".$DB_HOST." port=".$DB_PORT." dbname=".$DB_NAME." user=".$DB_USER." password=".$DB_PASS);
$result = pg_query($conexion, "SELECT a.username, u.email, u.created_at, a.display_name, last_sign_in_at FROM accounts a left join users u on u.account_id = a.id AND u.last_sign_in_at <= NOW() - INTERVAL '".$AWAY." months' WHERE a.suspended_at IS NULL and u.email is not null AND a.domain IS NULL ORDER BY u.last_sign_in_at ASC;");
if (!$result) die("Error de conexión con la base de datos.");
$datos = pg_fetch_all($result,PGSQL_ASSOC);
$meses = array("Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre");


foreach($datos as $usuario) {

    if ( !empty($DEBUG_MAIL)) $usuario['email'] = $DEBUG_MAIL;
    $usuario['fecha'] = date('d',strtotime($usuario['created_at']))." de ".$meses[date('n',strtotime($usuario['created_at']))-1]." de ".date('Y',strtotime($usuario['created_at']));
    $usuario['nombre'] = arregla($usuario['display_name']);
    if ( empty($usuario['nombre'])) $usuario['nombre'] = UCWords(strtolower($usuario['username']));
    echo $usuario['username']."\t".$usuario['email']."\t".$usuario['created_at']."\t".$usuario['fecha']."\t".$usuario['display_name']."\t".$usuario['nombre']." \t\t ";

    datos("mailer",$usuario['username'], $existe, true);

    if ( !$existe ) {
        unset($mail);
        $mail = new PHPMailer(true);
        try {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->isSMTP();
            $mail->Host       = $EMAIL_HOST;
            $mail->SMTPAuth   = $EMAIL_AUTH;
            $mail->Username   = $EMAIL_USER;
            $mail->Password   = $EMAIL_PASS;
            $mail->SMTPSecure = $EMAIL_ENC;
            $mail->Port       = $EMAIL_PORT;

            $mail->setFrom($EMAIL_FROM, 'Mastodon - '.$INSTANCIA);
            $mail->addAddress($usuario['email']);
        
            $mail->isHTML(true);
            $mail->Subject = 'Tu cuenta de Mastodon';

            $mail->AltBody = prepara($usuario, file_get_contents("mail.txt"));
            $mail->Body    = prepara($usuario, file_get_contents("mail.html"));
        
            $mail->send();
            echo 'ENVIADO';
        } catch (Exception $e) {
            echo "ERROR: {$mail->ErrorInfo}";
        }
        break; // Comentar este renglón para enviar todos los correos de golpe, recomendado solo si se usa un relay SMTP propio.
    }
    else {
        echo "SKIP";
    }
    echo "\n";
}
echo "\n";

function arregla($texto) {
    // Esto es una chapuza para quitar los emojis de Mastodon del nombre.
    $texto = preg_replace('#(:).*?(:)#', '$1', $texto);
    $texto = str_ireplace(":","",$texto);
    for($i=0;$i<=10;$i++) $texto = str_ireplace("  "," ",$texto);
    return trim($texto);
}

function prepara($usuario,$texto) {
    return str_ireplace(array("{NOMBRE}","{USUARIO}","{FECHA}"),array($usuario['nombre'],$usuario['username'],$usuario['fecha']),$texto);
}

function datos($seccion, $variable, &$existe, $valor=NULL, $borra=false) {
    // Pequeña funcion para crear una mini base de datos con los que he enviado para no enviar más.

    if ( !file_exists("./datos.db")) file_put_contents("./datos.db",base64_encode(serialize(array())));
    $datos = unserialize(base64_decode(file_get_contents("./datos.db")));
    if ( isset($datos[($seccion)][($variable)]) ) $existe = true;
    else $existe = false;

    if ( isset($valor) ) $datos[($seccion)][($variable)] = $valor;

    if ( isset($datos[($seccion)][($variable)]) ) {
        file_put_contents("./datos.db",base64_encode(serialize($datos)));
        return $datos[($seccion)][($variable)];
    }
    else return NULL;
}

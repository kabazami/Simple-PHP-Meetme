<?php
//Основные переменные для конфигурации
$asterisk_server = "127.0.0.1";
$asterisk_context = "localdials";
$asterisk_port = 5038;
$asterisk_manager_login = "manager";
$asterisk_manager_password = "manager_pass"; //Необходимые привилегии - call, all
$err_no = 0;
$err_str = "";

//Перевод нужных свойств пользователя конференции на русский
$translate_props["Conference"] = "Название конференции";
$translate_props["UserNumber"] = "Номер в конференции";
$translate_props["CallerIDNum"] = "Номер телефона пользователя";

$conference = $_POST['conference'];
$addnum = $_POST['addnum'];
$kicknum = $_POST['kicknum'];

//Парсим вывод AMI, выбираем нужные куски
function betweenStrings($start, $end, $str){
    $matches = array();
    $regex = '/'.preg_quote("Event: MeetmeList").'(.*?)'.preg_quote("Talking: ").'/s';
    preg_match_all($regex, $str, $matches);
    return $matches[1];
}

//Убираем лишние пробелы и символы перевода строки
function stripSpacesNls($text){
	$result = trim(preg_replace('/\ s+/', ' ', $text));
    return $result;
}

//Оставляем лишь числа
function onlyNumbers($text){
	$result = preg_replace('/[^0-9.]+/', '', $text);
    return $result;
}

//Генерируем рандомный набор символов для названия конференции
function randomStr($length){
  $chars = 'abdefhiknrstyzABDEFGHKNQRSTYZ23456789';
  $numChars = strlen($chars);
  $string = '';
  for ($i = 0; $i < $length; $i++) {
    $string .= substr($chars, rand(1, $numChars) - 1, 1);
  }
  return $string;
}

//Добавляем в существующую конференцию или создаём новую
if(isset($addnum)&&!empty($addnum)){
	$num_arr = explode(",", $addnum);
	if(!isset($conference)){
		$conference = "Conference_".randomStr(10);
		}
		foreach($num_arr as $phonetoadd){
			$socket = fsockopen($asterisk_server, $asterisk_port, $err_no, $err_str) or die("Не могу подключиться.");
				stream_set_timeout($socket, 2);
				fputs($socket, "Action: login\r\n");
				fputs($socket, "Events: off\r\n");
				fputs($socket, "Username: $asterisk_manager_login\r\n");
				fputs($socket, "Secret: $asterisk_manager_password\r\n\r\n");
				fputs($socket, "Action: Originate\r\n");
				fputs($socket, "Channel: Local/$phonetoadd@$asterisk_context\r\n");
				fputs($socket, "Async: true\r\n");
				fputs($socket, "Application: MeetMe\r\n");
				fputs($socket, "Data: $conference, d\r\n");
				fputs($socket, "CallerId: \"Conference call\" <$phonetoadd>\r\n");
				fputs($socket, "Priority: 1\r\n\r\n");
				while(!feof($socket)){
					$data = fgets($socket, 1024);
						if ($data==false ) {
							break; 
						}
					$text.="$data<br/>";
				}
				fputs($socket, "QUIT");
				fclose($socket);
	}
	header ("Location: ".$_SERVER['PHP_SELF']); //Перенаправляем на эту же страницу, исключая повторную отправку POST-параметров по нажатию f5 пользователем
}
//Удаляем из конференции (Не разобрался, как кикать с помощью Meetme, поэтому пока Hangup'ну канал)
else if(isset($kicknum)&&!empty($kicknum)){
	if(isset($conference)){
		$num_arr = explode(",", $kicknum);
		$text = '';
			foreach($num_arr as $phonetokick){
				$socket = fsockopen($asterisk_server, $asterisk_port, $err_no, $err_str) or die("Не могу подключиться.");
				stream_set_timeout($socket, 2);
				fputs($socket, "Action: login\r\n");
				fputs($socket, "Events: off\r\n");
				fputs($socket, "Username: $asterisk_manager_login\r\n");
				fputs($socket, "Secret: $asterisk_manager_password\r\n\r\n");
				fputs($socket, "Action: Hangup\r\n");
				fputs($socket, "Channel: $phonetokick\r\n\r\n");
				while(!feof($socket)){
					$data = fgets($socket, 1024);
						if ($data==false ) {
							break; 
						}
					$text.="$data<br/>";
				}
				fputs($socket, "QUIT");
				fclose($socket);
			}
	header ("Location: ".$_SERVER['PHP_SELF']); //Перенаправляем на эту же страницу, исключая повторную отправку POST-параметров
	}
	else{
		echo "Не указана конференция!"; //Оставлю на будущее, пригодится, когда разберусь с MeetMe Kick
	}
}
//Если не получаем ничего в GET, выводим список конференций и пользователей в них
else{
	$socket = fsockopen($asterisk_server, $asterisk_port, $err_no, $err_str) or die("Не могу подключиться.");
	stream_set_timeout($socket, 2);
	fputs($socket, "Action: login\r\n");
	fputs($socket, "Events: off\r\n");
	fputs($socket, "Username: $asterisk_manager_login\r\n");
	fputs($socket, "Secret: $asterisk_manager_password\r\n\r\n");
	fputs($socket, "Action: MeetMeList\r\n\r\n");
	fputs($socket, "Action: Logoff\r\n");
	$text = '';
	while(!feof($socket)){
		$data = fgets($socket, 1024);
			if ($data==false ) { break; }
				$text.="$data<br/>";
		}
		fputs($socket, "QUIT");
		fclose($socket);
		if(preg_match("/Message: No active conferences/s", $text)){
			echo "Нет активных конференций.";
			echo "<hr />
				<fieldset>
						<legend>Создать новую конференцию</legend>
							<form method=\"POST\">
							<input type=\"text\" name=\"addnum\" size=\"60\" placeholder=\"Перечислите номера участников конференции через запятую\" />
							<hr/>";
							echo "<button type=\"submit\">Создать конференцию</button>
							</form>
					</fieldset>
			";
		}
		else{
			$meetme_members = array();
			$arr = betweenStrings('Event: MeetmeList', 'Talking: ', $text);
			$i=0;
			foreach($arr as $key=>$value){
				$member_parameters = explode("<br/>", $value);
				$j=0;
				foreach($member_parameters as $val){
					if(!empty($val)&&$j>0){
						$param_arr = explode(":", $val);
						$meetme_members[$i][$param_arr[0]]=$param_arr[1];
					}
				$j++;
				}
				$i++;
			}

			$meetme_groupped = array();
			$i=0;
			foreach ($meetme_members as $member) {
				$conference = stripSpacesNls($meetme_members[$i]['Conference']);
				$phone_number = onlyNumbers($meetme_members[$i]['CallerIDNum']);
				if (array_key_exists($phone_number, $online_users)){
					$user_name = $online_users[$phone_number];
				}
				else{
					$user_name = $phone_number;
				}
				$meetme_groupped[$conference][$phone_number] = $member;
				$i++;
			}
			foreach ($meetme_groupped as $conference => $member) {
				echo "<h3>$conference</h3>";
				echo "<ul>";
					foreach ($member as $phone_number => $params){
						$conference_members[$phone_number] = $phone_number;
						echo "<li>".$user_name;
						echo "<ul>";
							foreach ($params as $param_name=>$param_value){
							$param_value = stripSpacesNls($param_value);
								if(array_key_exists($param_name, $translate_props)){
									echo "<li>".$translate_props[$param_name]." - $param_value</li>";
								}
								else if($param_name == "Channel"){
									$user_channel = $param_value;
								}
							}
						echo "</ul>";
						echo "</li>";
						echo "<form method=\"POST\">
								<input type=\"hidden\" name=\"conference\" value=\"$conference\" />
								<button name=\"kicknum\" value=\"$user_channel\" type=\"submit\">Убрать из конференции</button>
								</form>
							";
					}
				echo "</ul>";
				echo "<hr/>
					<fieldset>
							<legend>Добавить новых пользователей в эту конференцию</legend>
								<form method=\"POST\">
								<input type=\"hidden\" name=\"conference\" value=\"$conference\" />
										<input type=\"text\" name=\"addnum\" size=\"60\" placeholder=\"Перечислите номера участников конференции через запятую\" />
									<hr/>
									<button type=\"submit\">Добавить в конференцию</button>
								</form>
						</fieldset>
				";
			}
			echo "<hr />
				<fieldset>
						<legend>Создать новую конференцию</legend>
							<form method=\"POST\">
							<input type=\"text\" name=\"addnum\" size=\"60\" placeholder=\"Перечислите номера участников конференции через запятую\" /></div><hr/><button type=\"submit\">Создать конференцию</button>
							</form>
					</fieldset>
			";
		}
}
?>
<?php
/**
 * Created by PhpStorm.
 * User: spookie
 * Date: 05.04.2020
 * Time: 13:34
 */

class CAsyncSocket
{
	private $socket = null;

	/**
	 * @var boolean
	 */
	private $socket_error = false;

	/**
	 * буфер сокета. читаем данные в буфер и потом его уже разбираем
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Log prefix
	 *
	 * @return string
	 */
	private function p() {return 'CAsyncSocket('.strlen($this->buffer).'): ';}

	/**
	 * Флаг ошибки
	 *
	 * @return boolean
	 */
	public function error() {return $this->socket_error;}
	/**
	 * CAsyncSocket constructor.
	 * @param $server
	 * @param $port
	 */
	function __construct($server, $port)
	{
		// connect the socket
		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

		if (socket_connect($this->socket, $server, $port) === false) {
			msg("Unable to connect to manager {$server}:{$port}");
			return false;
		}

		msg("Socket opened");
		socket_set_nonblock($this->socket);

		//stream_set_timeout ($this->socket,30);
		/* 2013-05-17 добавил, чтобы найти проблему в зависании менеджера.
		ибо с утра висит, что последнее обновление статуса было в 20-55 вчера,
		очень вероятно программа зависает в случае таймаута
		в таком случае надо обнаруживать таймаут и пересоединяться
		30 секунд достаточно медленно чтобы не было дикого флуда ночью
		и достаточно быстро чтобы в случае реальной ошибки восстановить соединение*/

	}

	public function close() {
		socket_close($this->socket);
	}

	/**
	 * Читает из сокета в буффер
	 */
	function read_buff()
	{
		$r = [$this->socket];
		$w = NULL;
		$e = NULL;

		//проверяем, что в сокете есть что почитать
		$avail = socket_select($r, $w, $e, 0);
		if ($avail === false) {//ошибка
			$this->socket_error = true;
			msg ($this->p()."SOCKET OPEN ERR!");
			return false;
		} elseif ($avail > 0) {//чтото есть
			//читаем
			$read = socket_recv($this->socket, $buffer, 65536, 0); //got Use of undefined constant MSG_DONTWAIT in some cases
			if ($read === false) {//ошибка
				msg ($this->p()."SOCKET READ ERR!");
				/* if (socket_last_error($this->socket)===0)
				  socket_clear_error($this->socket);
				else*/
				$this->socket_error = true; //turning on PANIC!!!!! mode %-)
				return false;
			} elseif (strlen($buffer)) {
				//msg ($this->p()."RCVD:--< $buffer >--");
				$this->buffer .= $buffer; //добавляем прочитанное во внутреннее хранилище
				return true;
			}
		}
		return false;
	}


	/**
	 * принудительно ждет данных в сокете, если их там нет
	 *
	 * @param int $timeout
	 */
	function read_buff_wait($timeout = 300)
	{
		$start=microtime(true);
		while (
			($this->read_buff() === false) &&
			(!$this->socket_error) &&
			$timeout &&
			((microtime(true)-$start) < $timeout/1000)
		) usleep(10000);
	}


	/**
	 * Вытащить из буфера все
	 * Если выставлен таймаут, то может подождать появления данных
	 *
	 * @param bool $timeout
	 * @return string
	 */
	public function read_all($timeout=false) {
		$this->read_buff_wait($timeout);

		$buffer=$this->buffer;
		$this->buffer='';
		return $buffer;
	}

	/**
	 * @param bool $timeout
	 * @return bool|string
	 */
	public function read_line($timeout=false) {
		$this->read_buff_wait($timeout);

		if (strlen($this->buffer)) {
			$newline = strpos($this->buffer, "\r\n");
			if ($newline !== false) {
				$line = substr($this->buffer, 0, $newline);
				$this->buffer = substr($this->buffer, $newline + 2);
				return $line;
			} else return $this->read_all();
		}
	}

	/**
	 * Записать данные
	 *
	 * @param $data
	 */
	public function write($data) {
		socket_write($this->socket, $data);
	}
}
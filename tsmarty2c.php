#!/usr/bin/env php
<?php

/**
 * tsmarty2c.php - rips gettext strings from smarty template
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * This commandline script rips gettext strings from smarty file,
 * and prints them to stdout in already gettext encoded format, which you can
 * later manipulate with standard gettext tools.
 *
 * Usage:
 * ./tsmarty2c.php -o template.pot <filename or directory> <file2> <..>
 *
 * If a parameter is a directory, the template files within will be parsed.
 *
 * @package   smarty-gettext
 * @link      https://github.com/smarty-gettext/smarty-gettext/
 * @author    Sagi Bashari <sagi@boom.org.il>
 * @author    Elan Ruusamäe <glen@delfi.ee>
 * @copyright 2004-2005 Sagi Bashari
 * @copyright 2010-2017 Elan Ruusamäe
 */

class tsmarty2c {
	/**
	 * we msgcat found strings from each file.
	 * need header for each temporary .pot file to be merged.
	 * https://help.launchpad.net/Translations/YourProject/PartialPOExport
	 */
	const MSGID_HEADER = "msgid \"\"\nmsgstr \"Content-Type: text/plain; charset=UTF-8\\n\n\n";

	// smarty open tag
	private $ldq = '{'; // preg_quote

	// smarty close tag
	private $rdq = '}'; // preg_quote

	// smarty command
	private $cmd = 't'; // preg_quote

	// extensions of smarty files, used when going through a directory
	private $extensions = array('tpl');

	public function __construct() {
		$this->tmpdir = sys_get_temp_dir();

	}

	public function process() {
		list($options, $argc, $argv) = $this->getopt('o:');

		$outfile = isset($options['o']) ? $options['o'] : $this->tempfile();

		// initialize output
		file_put_contents($outfile, self::MSGID_HEADER);

		$files = $this->collect_files($argc);
		foreach ($files as $file) {
			$this->do_file($file);
		}

		// output and cleanup
		if (!isset($opt['o'])) {
			echo file_get_contents($outfile);
			unlink($outfile);
		}
	}

	// rips gettext strings from $file and prints them in C format
	private function do_file($outfile, $file) {
		$content = $this->readfile($file);

		if (!$content) {
			// FIXME throw?
			return;
		}

		global $ldq, $rdq, $cmd;

		preg_match_all(
			"/{$ldq}\s*({$cmd})\s*([^{$rdq}]*){$rdq}+([^{$ldq}]*){$ldq}\/\\1{$rdq}/",
			$content,
			$matches,
			PREG_OFFSET_CAPTURE
		);

		$result_msgctxt = array(); //msgctxt -> msgid based content
		$result_msgid = array(); //only msgid based content
		for ($i = 0; $i < count($matches[0]); $i++) {
			$msg_ctxt = null;
			$plural = null;
			if (preg_match('/context\s*=\s*["\']?\s*(.[^\"\']*)\s*["\']?/', $matches[2][$i][0], $match)) {
				$msg_ctxt = $match[1];
			}

			if (preg_match('/plural\s*=\s*["\']?\s*(.[^\"\']*)\s*["\']?/', $matches[2][$i][0], $match)) {
				$msgid = $matches[3][$i][0];
				$plural = $match[1];
			} else {
				$msgid = $matches[3][$i][0];
			}

			if ($msg_ctxt && empty($result_msgctxt[$msg_ctxt])) {
				$result_msgctxt[$msg_ctxt] = array();
			}

			if ($msg_ctxt && empty($result_msgctxt[$msg_ctxt][$msgid])) {
				$result_msgctxt[$msg_ctxt][$msgid] = array();
			} elseif (empty($result_msgid[$msgid])) {
				$result_msgid[$msgid] = array();
			}

			if ($plural) {
				if ($msg_ctxt) {
					$result_msgctxt[$msg_ctxt][$msgid]['plural'] = $plural;
				} else {
					$result_msgid[$msgid]['plural'] = $plural;
				}
			}

			$lineno = $this->lineno_from_offset($content, $matches[2][$i][1]);
			if ($msg_ctxt) {
				$result_msgctxt[$msg_ctxt][$msgid]['lineno'][] = "$file:$lineno";
			} else {
				$result_msgid[$msgid]['lineno'][] = "$file:$lineno";
			}
		}

		ob_start();
		echo self::MSGID_HEADER;
		foreach ($result_msgctxt as $msgctxt => $data_msgid) {
			foreach ($data_msgid as $msgid => $data) {
				echo "#: ", join(' ', $data['lineno']), "\n";

				echo 'msgctxt "' . $this->fs($msgctxt) . '"', "\n";
				echo 'msgid "' . $this->fs($msgid) . '"', "\n";
				if (isset($data['plural'])) {
					echo 'msgid_plural "' . fs($data['plural']) . '"', "\n";
					echo 'msgstr[0] ""', "\n";
					echo 'msgstr[1] ""', "\n";
				} else {
					echo 'msgstr ""', "\n";
				}
				echo "\n";
			}
		}

		// without msgctxt
		foreach ($result_msgid as $msgid => $data) {
			echo "#: ", join(' ', $data['lineno']), "\n";
			echo 'msgid "' . $this->fs($msgid) . '"', "\n";
			if (isset($data['plural'])) {
				echo 'msgid_plural "' . $this->fs($data['plural']) . '"', "\n";
				echo 'msgstr[0] ""', "\n";
				echo 'msgstr[1] ""', "\n";
			} else {
				echo 'msgstr ""', "\n";
			}
			echo "\n";
		}

		$out = ob_get_contents();
		ob_end_clean();
		$this->msgmerge($outfile, $out);
	}

	/**
	 * Find files from $dir matching extension.
	 *
	 * @param string $dir
	 * @return string[]
	 */
	private function find_dir($dir) {
		$files = array();

		$d = dir($dir);

		while (false !== ($entry = $d->read())) {
			if ($entry == '.' || $entry == '..') {
				continue;
			}

			$entry = $dir . '/' . $entry;

			if (is_dir($entry)) {
				$files = array_merge($files, $this->find_dir($entry));
			} elseif (is_file($entry)) {
				$pi = pathinfo($entry);

				if (isset($pi['extension']) && in_array($pi['extension'], $this->extensions)) {
					$files[] = $entry;
				}
			} else {
				// skip other than files (fifos, sockets, etc)
			}
		}

		$d->close();

		return $files;
	}

	private function msgmerge($outfile, $data) {
		// skip empty
		if (!$data) {
			// FIXME: throw?
			return;
		}

		// write new data to tmp file
		$tmp = $this->tempfile();
		file_put_contents($tmp, $data);

		// temp file for result cat
		$tmp2 = $this->tempfile();
		passthru('msgcat -o ' . escapeshellarg($tmp2) . ' ' . escapeshellarg($outfile) . ' ' . escapeshellarg($tmp), $rc);
		unlink($tmp);

		if ($rc) {
			throw new RuntimeException("msgcat failed with $rc");
		}

		// rename if output was produced
		if (file_exists($tmp2)) {
			rename($tmp2, $outfile);
		}
	}

	// "fix" string - strip slashes, escape and convert new lines to \n
	private function fs($str) {
		// FIXME: why stripslashes?!
		$str = stripslashes($str);
		$str = str_replace('"', '\"', $str);
		$str = str_replace("\n", '\n', $str);

		return $str;
	}

	private function lineno_from_offset($content, $offset) {
		return substr_count($content, "\n", 0, $offset) + 1;
	}

	/**
	 * process arguments for dirs and files.
	 * files are implicitly added to list,
	 * directories are scanned for matching extension
	 *
	 * @param array $args
	 * @return array files list
	 */
	private function collect_files($args) {
		$files = array();
		foreach ($args as $arg) {
			if (is_dir($arg)) {
				$this->find_dir($arg);
			} elseif (is_file($arg)) {
				$files[] = $arg;
			} else {
				throw new InvalidArgumentException("Not file or dir: '$arg'");
			}
		}

		return $files;
	}

	/**
	 * @return string
	 */
	private function tempfile() {
		$tmpfile = tempnam($this->tmpdir, 'tsmarty2c');
		if ($tmpfile === false) {
			throw new RuntimeException('Could not create temporary file');
		}

		return $tmpfile;
	}

	/**
	 * @param string $file
	 * @return string
	 */
	private function readfile($file) {
		$content = file_get_contents($file);
		if ($content === false) {
			throw new RuntimeException("Could not read $file");
		}

		return $content;
	}

	/**
	 * A bit more getopt() ajusted from this post:
	 * http://php.net/getopt#100573
	 *
	 * It still gives results if invalid argument is passed,
	 * invalid options stay in $argv as arguments.
	 */
	private function getopt($parameters) {
		global $argv, $argc;

		$options = getopt($parameters);
		$pruneargv = array();
		foreach ($options as $option => $value) {
			foreach ($argv as $key => $chunk) {
				$regex = '/^' . (isset($option[1]) ? '--' : '-') . $option . '/';
				if ($chunk == $value && $argv[$key - 1][0] == '-' || preg_match($regex, $chunk)) {
					array_push($pruneargv, $key);
				}
			}
		}

		while ($key = array_pop($pruneargv)) {
			unset($argv[$key]);
		}

		// renumber $argv to be continuous
		$argv = array_values($argv);
		// reset $argc to be correct
		$argc = count($argv);

		return array($options, $argc, $argv);
	}
}

// run as cli not library mode?
if (isset($argv[0]) && basename($argv[0]) == basename(__FILE__)) {
	try {
		if ('cli' != php_sapi_name()) {
			throw new RuntimeException("This program is for command line mode only.");
		}

		$cli = new tsmarty2c();
		$cli->process();

	} catch (Exception $e) {
		error_log("ERROR: {$e->getMessage()}");
		exit(1);
	}
}

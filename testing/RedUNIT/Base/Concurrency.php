<?php

namespace RedUNIT\Base;

use RedUNIT\Base as Base;
use RedBeanPHP\Facade as R;
use RedBeanPHP\RedException as RedException;
use RedBeanPHP\OODBBean as OODBBean;

/**
 * Concurrency
 *
 * Tests whether we can lock beans.
 *
 * @file    RedUNIT/Base/Concurrency.php
 * @desc    Tests concurrency scenarios
 * @author  Gabor de Mooij and the RedBeanPHP Community
 * @license New BSD/GPLv2
 *
 * (c) G.J.G.T. (Gabor) de Mooij and the RedBeanPHP Community.
 * This source file is subject to the New BSD/GPLv2 License that is bundled
 * with this source code in the file license.txt.
 */
class Concurrency extends Base
{
	/**
	 * Returns the target drivers for this test.
	 * This test only works with Postgres and MySQL/MariaDB.
	 *
	 * @return array
	 */
	public function getTargetDrivers()
	{
		return array( 'pgsql','mysql' );
	}

	/**
	 * Prepares the database connection.
	 *
	 * @return void
	 */
	public function prepare()
	{
		R::close();
	}

	/**
	 * This test has to be run manually.
	 *
	 * @return void
	 */
	private function testLockException()
	{
		R::nuke();
		$lock = R::dispense('lock');
		$lock->value = 123;
		R::store($lock);
		$c = pcntl_fork();
		if ($c == -1) exit(1);
		if (!$c) {
			R::selectDatabase($this->currentlyActiveDriverID . 'c2');
			R::freeze(TRUE);
			try { R::exec('SET SESSION innodb_lock_wait_timeout=5');}catch( \Exception $e ){}
			try { R::exec('SET autocommit = 0'); }catch( \Exception $e ){}
			R::begin();
			$lock = R::loadForUpdate('lock', $lock->id);
			$lock->value = 4;
			sleep(10);
			R::store($lock);
			exit(0);
		} else {
			R::selectDatabase($this->currentlyActiveDriverID . 'c2');
			sleep(1);
			R::freeze(TRUE);
			try{ R::exec('SET SESSION innodb_lock_wait_timeout=5');}catch( \Exception $e ){}
			try{R::exec("SET lock_timeout = '1s';");}catch( \Exception $e ){}
			try { R::exec('SET autocommit = 0'); }catch( \Exception $e ){}
			R::begin();
			$exception = NULL;
			try {
				$lock = R::loadForUpdate('lock', $lock->id);
			} catch( \Exception $e ) {
				$exception = $e;
			}
			if ( !$exception ) fail();
			pass();
			$details = $exception->getDriverDetails();
			asrt( ($details[1]===1205 || $details[0]==='55P03'), TRUE );
			var_dump($lock);
		}
		try { R::exec('SET autocommit = 1'); }catch( \Exception $e ){}
		pcntl_wait($status);
		try { R::exec('SET SESSION innodb_lock_wait_timeout=50');}catch( \Exception $e ){}
		try{R::exec("SET lock_timeout = '50s';");}catch( \Exception $e ){}
	}

	/**
	 * Tests basic locking scenarios using fork().
	 *
	 * @return void
	 */
	public function testConcurrency()
	{
		$c = pcntl_fork();
		if ($c == -1) exit(1);
		if (!$c) {
			R::selectDatabase($this->currentlyActiveDriverID . 'c');
			try{ R::exec('SET SESSION innodb_lock_wait_timeout=51');}catch( \Exception $e ){}
			try{R::exec("SET lock_timeout = '51s';");}catch( \Exception $e ){}
			R::exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
			sleep(1);
			try { R::exec('SET autocommit = 0'); }catch( \Exception $e ){}
			R::freeze(true);
			R::begin();
			echo "CHILD: SUBTRACTING 2 START\n";
			$i = R::loadForUpdate('inventory', 1);
			$i->apples -= 2;
			sleep(4);
			R::store($i);
			R::commit();
			echo "CHILD: SUBTRACTING 2 DONE\n";
			echo (R::load('inventory', 1));
			echo "\n";
			exit(0);
		} else {
			R::selectDatabase($this->currentlyActiveDriverID . 'c');
			try{ R::exec('SET SESSION innodb_lock_wait_timeout=51');}catch( \Exception $e ){}
			try{R::exec("SET lock_timeout = '51s';");}catch( \Exception $e ){}
			echo "PARENT: PREP START\n";
			R::nuke();
			$i = R::dispense('inventory');
			$i->apples = 10;
			R::store($i);
			R::exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
			echo "PARENT: PREP DONE\n";
			sleep(2);
			echo "PARENT: ADDING 5 START\n"; 
			try { R::exec('SET autocommit = 0'); }catch( \Exception $e ){}
			R::freeze(true);
			R::begin();
			$i = R::loadForUpdate('inventory', 1);
			print_r($i);
			$i->apples += 5;
			R::store($i);
			R::commit();
			echo "PARENT ADDING 5 DONE\n";
			$i = R::getAll('select * from inventory where id = 1');
			print_r($i);
			asrt((int)$i[0]['apples'], 13);
			R::freeze(false);
			try { R::exec('SET autocommit = 1'); }catch( \Exception $e ){}
			pcntl_wait($status); 
		}
	}
}

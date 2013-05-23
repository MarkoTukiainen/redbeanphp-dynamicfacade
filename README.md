redbeanphp-dynamicfacade
========================

A non-static facade for RedBeanPHP. 

This class provides a non-static facade to RedBeanPHP.
If you don't know what that means, you probably won't need this.
This may or may not implement all of the functionality of the
original, static facade, so use with caution.

See license.txt for licensing details. 


Usage example
-------------

Instead of using R:: calls, you can create a regular object. 

	include 'rb.php'; 
	include 'class.database.php';

	$db = new Aptual\Database(); 
	$db->setup('sqlite://tmp/dynamicfacade.db');

	$book = $db->dispense("book");
	$book->author = "Santa Claus";
	$book->title = "Secrets of Christmas";
	$id = $db->store($book);


Advanced features
-----------------

You can define a model helper like this:

	$db->getModelHelper()->setModelFormatter(new \MyFancyModelFormatter());

And a dependency injector like this:

	$di = new RedBean_DependencyInjector();
	$db->getModelHelper()->setDependencyInjector($di);

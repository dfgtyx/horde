<?php

function migrate_sql($db)
{
    $migration = new Horde_Db_Migration_Base($db);

    /* Cleanup potential left-overs. */
    try {
        $migration->dropTable('test_somebaseobjects');
        $migration->dropTable('test_relatedthings');
    } catch (Horde_Db_Exception $e) {
    }

    $t = $migration->createTable('test_somebaseobjects', array('autoincrementKey' => 'baseobject_id'));
    $t->column('relatedthing_id', 'integer');
    $t->column('atextproperty', 'string');
    $t->end();

    $t = $migration->createTable('test_relatedthings', array('autoincrementKey' => 'relatedthing_id'));
    $t->column('relatedthing_textproperty', 'string', array('limit' => 255, 'null' => false));
    $t->column('relatedthing_intproperty', 'integer', array('null' => false));
    $t->end();

    $migration->migrate('up');
}

<?php
/**
 * Horde_ActiveSync_Connector_Importer:: Class for importing message changes
 * from the PIM to the backend.
 *
 * @copyright 2010-2012 Horde LLC (http://www.horde.org/)
 * @author Michael J Rubinsky <mrubinsk@horde.org>
 * @package ActiveSync
 */
/**
 * Connector class for importing ActiveSync messages from the wbxml input stream.
 *
 * Copyright 2010-2012 Horde LLC (http://www.horde.org/)
 *
 * @author  Michael J Rubinsky <mrubinsk@horde.org>
 * @package ActiveSync
 */

class Horde_ActiveSync_Connector_Importer
{
    /**
     * State machine
     *
     * @var Horde_ActiveSync_State_Base
     */
    protected $_state;

    /**
     * The backend driver for communicating with the server we are syncing with.
     *
     * @var Horde_ActiveSync_Driver_Base
     */
    protected $_backend;

    /**
     * Conflict resolution flags
     *
     * @var integer
     */
    protected $_flags;

    /**
     * The server specific folder id
     *
     * @var string
     */
    protected $_folderId;

    /**
     * Logger
     *
     * @var Horde_Log_Logger
     */
    protected $_logger;

    /**
     * Const'r
     *
     * @param Horde_ActiveSync_Driver_Base $backend
     */
    public function __construct(Horde_ActiveSync_Driver_Base $backend)
    {
        $this->_backend = $backend;
    }

    /**
     * Initialize the exporter for this collection
     *
     * @param Horde_ActiveSync_State_Base $state  The state machine.
     * @param string $folderId                    The collection's id.
     * @param integer $flags                      Conflict resolution flags.
     */
    public function init(Horde_ActiveSync_State_Base &$state, $folderId = null, $flags = 0)
    {
        $this->_state = &$state;
        $this->_flags = $flags;
        $this->_folderId = $folderId;
    }

    /**
     * Setter for a logger instance
     *
     * @param Horde_Log_Logger $logger  The logger
     */
    public function setLogger($logger)
    {
        $this->_logger = $logger;
    }

    /**
     * Import a message change from the wbxml stream
     *
     * @param string|boolean $id                       A server message id or
     *                                                 false if a new message.
     * @param Horde_ActiveSync_Message_Base $message   A message object
     * @param StdClass $device                         A device descriptor
     * @param integer $clientid                        Client id sent from PIM
     *                                                 on message addition.
     *
     * @return string|boolean The server message id or false
     */
    public function importMessageChange($id, $message, $device, $clientid)
    {
        if ($this->_folderId == Horde_ActiveSync::FOLDER_TYPE_DUMMY) {
            return false;
        }

        // Changing an existing object
        if ($id) {
            $conflict = $this->_isConflict(
                Horde_ActiveSync::CHANGE_TYPE_CHANGE,
                $this->_folderId,
                $id);

            if ($conflict && $this->_flags == Horde_ActiveSync::CONFLICT_OVERWRITE_PIM) {
                return $id;
            }
        } else {
            if ($uid = $this->_state->isDuplicatePIMAddition($clientid)) {
                // Already saw this addition, but PIM never received UID
                return $uid;
            }
        }

        // Tell the backend about the change
        if (!$stat = $this->_backend->changeMessage($this->_folderId, $id, $message, $device)) {
            return false;
        }
        $stat['parent'] = $this->_folderId;

        // Record the state of the message
        $this->_state->updateState(
            Horde_ActiveSync::CHANGE_TYPE_CHANGE,
            $stat,
            Horde_ActiveSync::CHANGE_ORIGIN_PIM,
            $this->_backend->getUser(),
            $clientid);

        return $stat['id'];
    }

    /**
     * Import a message deletion. This may conflict if the local object has been
     * modified.
     *
     * @param string $id  Server message uid
     *
     * @return boolean
     */
    public function importMessageDeletion($id)
    {
        if ($this->_folderId == Horde_ActiveSync::FOLDER_TYPE_DUMMY) {
            return true;
        }

        $conflict = $this->_isConflict(
            Horde_ActiveSync::CHANGE_TYPE_DELETE, $this->_folderId, $id);

        // Update client state
        $change = array();
        $change['id'] = $id;
        $change['mod'] = time();
        $change['parent'] = $this->_folderId;
        $this->_state->updateState(
            Horde_ActiveSync::CHANGE_TYPE_DELETE,
            $change,
            Horde_ActiveSync::CHANGE_ORIGIN_PIM,
            $this->_backend->getUser());

        // If server wins the conflict, don't import change - it will be
        // detected on next sync and sent back to PIM (since we updated the PIM
        // state).
        if ($conflict && $this->_flags == Horde_ActiveSync::CONFLICT_OVERWRITE_PIM) {
            return true;
        }

        // Tell backend about the deletion
        $this->_backend->deleteMessage($this->_folderId, $id);

        return true;
    }

    /**
     * Import a change in 'read' flags .. This can never conflict
     *
     * @param string $id        Server message id
     * @param string $flags     The read flags to set
     */
    public function importMessageReadFlag($id, $flags)
    {
        if ($this->_folderId == Horde_ActiveSync::FOLDER_TYPE_DUMMY) {
            return true;
        }

        $change = array();
        $change['id'] = $id;
        $change['flags'] = $flags;
        $change['parent'] = $this->_folderId;
        $this->_state->updateState(
            Horde_ActiveSync::CHANGE_TYPE_FLAGS,
            $change,
            Horde_ActiveSync::CHANGE_ORIGIN_PIM,
            $this->_backend->getUser());

        // @TODO: These methods should be passed a Folder object.
        $this->_backend->setReadFlag($this->_folderId, $id, $flags);

        return true;
    }

    /**
     * Perform a message move initiated on the PIM
     *
     * @param string $uid   The source message id
     * @param string $dst  The destination folder id.
     *
     * @return array
     */
    public function importMessageMove($uid, $dst)
    {
        return $this->_backend->moveMessage($this->_folderId, $uid, $dst);
    }

    /**
     * Import a folder change from the wbxml stream
     *
     * @param string $id            The folder id
     * @param string $parent        The parent folder id?
     * @param string $displayname   The folder display name
     * @param integer $type         The collection type.
     *
     * @return string|boolean  The new serverid if successful, otherwise false.
     */
    public function importFolderChange($id, $displayname, $parent = Horde_ActiveSync::FOLDER_ROOT)
    {
        /* do nothing if it is a dummy folder */
        if ($parent === Horde_ActiveSync::FOLDER_TYPE_DUMMY) {
            return false;
        }
        try {
            $change_res = $this->_backend->changeFolder($id, $displayname, $parent);
        } catch (Horde_Exception $e) {
            return false;
        }
        $change = array();
        $change['id'] = empty($id) ? $displayname : $id;
        $change['mod'] = $displayname;
        $change['parent'] = $parent;
        $this->_state->updateState(
            Horde_ActiveSync::CHANGE_TYPE_CHANGE,
            $change,
            Horde_ActiveSync::CHANGE_ORIGIN_PIM);


        return $change_res;
    }

    /**
     * Imports a folder deletion from the PIM
     *
     * @param string $id      The folder id
     * @param string $parent  The folder id of the parent folder
     *
     * @return boolean
     */
    public function importFolderDeletion($id, $parent = Horde_ActiveSync::FOLDER_ROOT)
    {
        /* Do nothing if it is a dummy folder */
        if ($parent === Horde_ActiveSync::FOLDER_TYPE_DUMMY) {
            return false;
        }

        $change = array();
        $change['id'] = $id;
        try {
            $this->_backend->deleteFolder($id, $parent);
        } catch (Horde_Exception $e) {
            return false;
        }
        $this->_state->updateState(
            Horde_ActiveSync::CHANGE_TYPE_DELETE,
            $change,
            Horde_ActiveSync::CHANGE_ORIGIN_NA);

        return true;
    }

    /**
     *  Check if this change conflicts with server changes
     *  This is only true in the following situations:
     *
     *    Changed here and changed there
     *    Changed here and deleted there
     *    Deleted here and changed there
     *
     * Any other combination of operations can be done
     * (e.g. change flags & move or move & delete)
     *
     * @param string $type  The type of change('change', 'delete' etc...)
     * @param string $folderid  The id of the folder this change is from.
     * @param string $id        The uid for the changed message.
     *
     * @return boolean
     */
    protected function _isConflict($type, $folderid, $id)
    {
        $stat = $this->_backend->statMessage($folderid, $id);
        if (!$stat) {
            /* Message is gone, if type is change, this is a conflict */
            if ($type == Horde_ActiveSync::CHANGE_TYPE_CHANGE) {
                return true;
            } else {
                return false;
            }
        }

        return $this->_state->isConflict($stat, $type);
    }

}

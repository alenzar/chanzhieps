<?php
/**
 * The model file of reply module of chanzhiEPS.
 *
 * @copyright   Copyright 2013-2013 青岛息壤网络信息有限公司 (QingDao XiRang Network Infomation Co,LTD www.xirangit.com)
 * @license     LGPL
 * @author      Chunsheng Wang <chunsheng@cnezsoft.com>
 * @package     reply
 * @version     $Id$
 * @link        http://www.chanzhi.org
 */
class replyModel extends model
{
    /**
     * Get a reply by it's id.
     * 
     * @param  int    $replyID 
     * @access public
     * @return object
     */
    public function getByID($replyID)
    {
        $reply = $this->dao->findById($replyID)->from(TABLE_REPLY)->fetch();
        if(!$reply) return false;

        $reply->files = $this->loadModel('file')->getByObject('reply', $replyID);
        return $reply;
    }

    /**
     * Get position of reply.
     * 
     * @param  int    $replyID 
     * @access public
     * @return string
     */
    public function getPosition($replyID)
    {
        $reply = $this->getByID($replyID);
        if(!$reply) return '';

        $replies = $this->dao->select('COUNT(id) as id')->from(TABLE_REPLY)
            ->where('thread')->eq($reply->thread)
            ->andWhere('id')->lt($replyID)
            ->andWhere('hidden')->eq('0')
            ->fetch('id');

        $pageID   = (int)($replies / 10);
        $position = $pageID ? "pageID=" . ($pageID + 1) . "&replyID=$replyID": "replyID=$replyID";

        return $position;
    }

    /**
     * Get replies of a thread.
     * 
     * @param  int    $thread 
     * @param  object $pager 
     * @access public
     * @return array
     */
    public function getByThread($thread, $pager = null)
    {
        $replies = $this->dao->select('*')->from(TABLE_REPLY)
            ->where('thread')->eq($thread)
            ->orderBy('id')
            ->page($pager)
            ->fetchAll('id');

        if(!$replies) return array();

        $this->setRealNames($replies);

        /* Get files for these replies. */
        $files = $this->loadModel('file')->getByObject('reply', array_keys($replies));
        
        foreach($files as $replyID => $file) $replies[$replyID]->files = $file;

        return $replies;
    }

    /**
     * Get replies. 
     * 
     * @param  object $pager 
     * @access public
     * @return object | false
     */
    public function getList($orderBy = 'addedDate_desc', $pager = null)
    {
        $replies = $this->dao->select('*')->from(TABLE_REPLY)
            ->orderBy($orderBy)
            ->page($pager)
            ->fetchAll('id');

        $this->setRealNames($replies);

        return $replies;
    }

    /**
     * Get replies of a user.
     * 
     * @param string $account       the account
     * @param string $pager         the pager object
     * @access public
     * @return array
     */
    public function getByUser($account, $pager)
    {
        $replies = $this->dao->select('t1.*, t2.title')->from(TABLE_REPLY)->alias('t1')
            ->leftJoin(TABLE_THREAD)->alias('t2')->on('t1.thread = t2.id')
            ->where('t1.author')->eq($account)
            ->orderBy('t1.id desc')
            ->page($pager)
            ->fetchAll('id');
        return $replies;
    }

    /**
     * Reply a thread.
     * 
     * @param  int      $threadID 
     * @access public
     * @return void
     */
    public function post($threadID)
    {
        $thread = $this->loadModel('thread')->getByID($threadID);
        $allowedTags = $this->app->user->admin == 'super' ? $this->config->allowedTags->admin : $this->config->allowedTags->front;
        $reply = fixer::input('post')
            ->setForce('author', $this->app->user->account)
            ->setForce('addedDate', helper::now())
            ->setForce('thread', $threadID)
            ->stripTags('content', $allowedTags)
            ->remove('recTotal, recPerPage, pageID, files, labels, hidden')
            ->get();

        $this->dao->insert(TABLE_REPLY)
            ->data($reply, $skip = 'captcha, uid')
            ->autoCheck()
            ->batchCheck('content', 'notempty')
            ->check('captcha', 'captcha')
            ->exec();

        $replyID = $this->dao->lastInsertID();                     // Get reply id.

        $this->loadModel('file')->updateObjectID($this->post->uid, $replyID, 'reply');

        if(!dao::isError())
        {
            $this->saveCookie($replyID);                               // Save reply id to cookie.
            $this->loadModel('file')->saveUpload('reply', $replyID);   // Save file.

            /* Update thread stats. */
            $this->thread->updateStats($threadID);

            /* Update board stats. */
            $this->loadModel('forum')->updateBoardStats($thread->board);

            return $replyID;
        }
        return false;
    }

    /**
     * Update a reply.
     * 
     * @param  int      $replyID 
     * @access public
     * @return void
     */
    public function update($replyID)
    {
        $allowedTags = $this->app->user->admin == 'super' ? $this->config->allowedTags->admin : $this->config->allowedTags->front;

        $reply = fixer::input('post')
            ->setForce('editor', $this->session->user->account)
            ->setForce('editedDate', helper::now())
            ->stripTags('content', $allowedTags)
            ->remove('files,labels,hidden')
            ->get();

        $this->dao->update(TABLE_REPLY)
            ->data($reply, $skip = 'captcha, uid')
            ->autoCheck()
            ->check('content', 'notempty')
            ->check('captcha', 'captcha')
            ->where('id')->eq($replyID)
            ->exec();

        $this->loadModel('file')->updateObjectID($this->post->uid, $replyID, 'reply');

        if(!dao::isError())
        {
            $this->loadModel('file')->saveUpload('reply', $replyID);
            return true;
        }

        return false;
    }

    /**
     * Hide a reply. 
     * 
     * @param  int      $replyID 
     * @access public
     * @return void
     */
    public function hide($replyID)
    {
        $this->dao->update(TABLE_REPLY)->set('hidden')->eq(1)->where('id')->eq($replyID)->exec();
    }

    /**
     * Delete a reply.
     * 
     * @param string $replyID 
     * @access public
     * @return void
     */
    public function delete($replyID, $null = null)
    {
        $thread = $this->dao->select('t2.id, t2.board')->from(TABLE_REPLY)->alias('t1')
            ->leftJoin(TABLE_THREAD)->alias('t2')
            ->on('t1.thread = t2.id')
            ->where('t1.id')->eq($replyID)
            ->fetch();

        $this->dao->delete()->from(TABLE_REPLY)->where('id')->eq($replyID)->exec(false);
        if(dao::isError()) return false;

        /* Update thread and board stats. */
        $this->loadModel('thread')->updateStats($thread->id);
        $this->loadModel('forum')->updateBoardStats($thread->board);
        return !dao::isError();
    }

    /**
     * Print files of for a reply.
     * 
     * @param  object $thread 
     * @param  bool   $canManage 
     * @access public
     * @return void
     */
    public function printFiles($reply, $canManage)
    {
        if(empty($reply->files)) return false;

        $imagesHtml = '';
        $filesHtml  = '';

        foreach($reply->files as $file)
        {
            if($file->isImage)
            {
                $imagesHtml .= "<li class='file-image file-{$file->extension}'>" . html::a(helper::createLink('file', 'download', "fileID=$file->id&mose=left"), html::image($file->fullURL), "target='_blank' data-toggle='lightbox'");
                if($canManage) $imagesHtml .= "<span class='file-actions'>" . html::a(helper::createLink('reply', 'deleteFile', "replyID=$reply->id&fileID=$file->id"), "<i class='icon-trash'></i>", "class='deleter'") . '</span>';
                $imagesHtml .= '</li>';
            }
            else
            {
                $file->title = $file->title . ".$file->extension";
                $filesHtml .= "<li class='file file-{$file->extension}'>" . html::a(helper::createLink('file', 'download', "fileID=$file->id&mouse=left"), $file->title, "target='_blank'");
                if($canManage) $filesHtml .= "<span class='file-actions'>" . html::a(helper::createLink('reply', 'deleteFile', "replyID=$reply->id&fileID=$file->id"), "<i class='icon-trash'></i>", "class='deleter'") . '</span>';
                $filesHtml .= '</li>';
            }
        }
        echo "<ul class='article-files clearfix'><li class='article-files-heading'>". $this->lang->reply->files . '</li>' . $imagesHtml . $filesHtml . '</ul>';
    }

    /**
     * Save the reply id to cookie.
     * 
     * @param  int     $replyID 
     * @access public
     * @return void
     */
    public function saveCookie($reply)
    {
        $reply = "$reply,";
        $cookie = $this->cookie->r != false ? $this->cookie->r : ',';
        if(strpos($cookie, $reply) === false) $cookie .= $reply;
        setcookie('r', $cookie , time() + 60 * 60 * 24 * 30);
    }

    /**
     * Set real name for author and editor of replies.
     * 
     * @param  array     $replies 
     * @access public
     * @return void
     */
    public function setRealNames($replies)
    {
        $speakers = array();
        foreach($replies as $reply)
        {
            $speakers[] = $reply->author;
            $speakers[] = $reply->editor;
        }

        $speakers = $this->loadModel('user')->getRealNamePairs($speakers);

        foreach($replies as $reply) 
        {
           $reply->authorRealname    = !empty($reply->author) ? $speakers[$reply->author] : '';
           $reply->editorRealname    = !empty($reply->editor) ? $speakers[$reply->editor] : '';
        }
    }
}

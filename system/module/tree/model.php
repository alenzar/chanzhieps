<?php
/**
 * The model file of tree module of chanzhiEPS.
 *
 * @copyright   Copyright 2013-2013 青岛息壤网络信息有限公司 (QingDao XiRang Network Infomation Co,LTD www.xirangit.com)
 * @license     LGPL
 * @author      Chunsheng Wang <chunsheng@cnezsoft.com>
 * @package     tree
 * @version     $Id$
 * @link        http://www.chanzhi.org
 */
?>
<?php
class treeModel extends model
{
    /**
     * Get category info by id.
     * 
     * @param  int|string  $categoryID 
     * @param  string      $type 
     * @access public
     * @return bool|object
     */
    public function getByID($categoryID, $type = 'article')
    {
        $category = $this->dao->select('*')->from(TABLE_CATEGORY)->where('alias')->eq($categoryID)->andWhere('type')->eq($type)->fetch();
        if(!$category) $category = $this->dao->findById((int)$categoryID)->from(TABLE_CATEGORY)->fetch();

        if(!$category) return false;

        $category->pathNames = $this->dao->select('id, name')->from(TABLE_CATEGORY)->where('id')->in($category->path)->orderBy('grade')->fetchPairs();
        return $category;
    }

    /**
     * Get category alias by id.
     * 
     * @param  int      $categoryID 
     * @access public
     * @return string
     */
    public function getAliasByID($categoryID)
    {
        if(isset($this->config->categoryAlias[$categoryID])) return $this->config->categoryAlias[$categoryID];
        return "";
    }

    /**
     * Get the first category.
     * 
     * @param  string $type 
     * @access public
     * @return object|bool
     */
    public function getFirst($type = 'article')
    {
        return $this->dao->select('*')->from(TABLE_CATEGORY)->where('type')->eq($type)->orderBy('id')->limit(1)->fetch();
    }

    /**
     * Get the id => name pairs of some categories.
     * 
     * @param  string $categories   the category lists
     * @param  string $type         the type
     * @access public
     * @return array
     */
    public function getPairs($categories = '', $type = 'article')
    {
        return $this->dao->select('id, name')->from(TABLE_CATEGORY)
            ->beginIF($categories)->where('id')->in($categories)->fi()
            ->beginIF($type)->where('type')->eq($type)->fi()
            ->fetchPairs();
    }

    /**
     * Get origin of a category.
     * 
     * @param  int     $categoryID 
     * @access public
     * @return array
     */
    public function getOrigin($categoryID)
    {
        if($categoryID == 0) return array();

        $path = $this->dao->select('path')->from(TABLE_CATEGORY)->where('id')->eq((int)$categoryID)->fetch('path');
        $path = trim($path, ',');
        if(!$path) return array();

        return $this->dao->select('*')->from(TABLE_CATEGORY)->where('id')->in($path)->orderBy('grade')->fetchAll('id');
    }

    /**
     * Get id list of a family.
     * 
     * @param  int      $categoryID 
     * @param  string   $type 
     * @access public
     * @return array
     */
    public function getFamily($categoryID, $type = '')
    {
        if($categoryID == 0 and empty($type)) return array();
        $category = $this->getById($categoryID);

        if($category)  return $this->dao->select('id')->from(TABLE_CATEGORY)->where('path')->like($category->path . '%')->fetchPairs();
        if(!$category) return $this->dao->select('id')->from(TABLE_CATEGORY)->where('type')->eq($type)->fetchPairs();
    }

    /**
     * Get children categories of one category.
     * 
     * @param  int      $categoryID 
     * @param  string   $type 
     * @access public
     * @return array
     */
    public function getChildren($categoryID, $type = 'article')
    {
        return $this->dao->select('*')->from(TABLE_CATEGORY)
            ->where('parent')->eq((int)$categoryID)
            ->andWhere('type')->eq($type)
            ->orderBy('`order`')
            ->fetchAll('id');
    }

    /**
     * Build the sql to execute.
     * 
     * @param string $type              the tree type, for example, article|forum
     * @param int    $startCategory     the start category id
     * @access public
     * @return string
     */
    public function buildQuery($type, $startCategory = 0)
    {
        /* Get the start category path according the $startCategory. */
        $startPath = '';
        if($startCategory > 0)
        {
            $startCategory = $this->getById($startCategory);
            if($startCategory) $startPath = $startCategory->path . '%';
        }

        return $this->dao->select('*')->from(TABLE_CATEGORY)
            ->where('type')->eq($type)
            ->beginIF($startPath)->andWhere('path')->like($startPath)->fi()
            ->orderBy('grade desc, `order`')
            ->get();
    }

    /**
     * Create a tree menu in <select> tag.
     * 
     * @param  string $type 
     * @param  int    $startCategory 
     * @param  bool   $removeRoot 
     * @access public
     * @return string
     */
    public function getOptionMenu($type = 'article', $startCategory = 0, $removeRoot = false)
    {
        /* First, get all categories. */
        $treeMenu   = array();
        $lastMenu   = array();
        $stmt       = $this->dbh->query($this->buildQuery($type, $startCategory));
        $categories = array();
        while($category = $stmt->fetch()) $categories[$category->id] = $category;

        /* Cycle them, build the select control.  */
        foreach($categories as $category)
        {
            $origins = explode(',', $category->path);
            $categoryName = '/';
            foreach($origins as $origin)
            {
                if(empty($origin)) continue;
                $categoryName .= $categories[$origin]->name . '/';
            }
            $categoryName = rtrim($categoryName, '/');
            $categoryName .= "|$category->id\n";

            if(isset($treeMenu[$category->id]) and !empty($treeMenu[$category->id]))
            {
                if(isset($treeMenu[$category->parent]))
                {
                    $treeMenu[$category->parent] .= $categoryName;
                }
                else
                {
                    $treeMenu[$category->parent] = $categoryName;;
                }
                $treeMenu[$category->parent] .= $treeMenu[$category->id];
            }
            else
            {
                if(isset($treeMenu[$category->parent]) and !empty($treeMenu[$category->parent]))
                {
                    $treeMenu[$category->parent] .= $categoryName;
                }
                else
                {
                    $treeMenu[$category->parent] = $categoryName;
                }    
            }
        }

        $topMenu = @array_pop($treeMenu);
        $topMenu = explode("\n", trim($topMenu));
        if(!$removeRoot) $lastMenu[] = '/';

        foreach($topMenu as $menu)
        {
            if(!strpos($menu, '|')) continue;

            $menu       = explode('|', $menu);
            $label      = array_shift($menu);
            $categoryID = array_pop($menu);
           
            $lastMenu[$categoryID] = $label;
        }

        return $lastMenu;
    }

    /**
     * Get the tree menu in <ul><ol> type.
     * 
     * @param string    $type           the tree type
     * @param int       $startCategoryID  the start category
     * @param string    $userFunc       which function to be called to create the link
     * @access public
     * @return string   the html code of the tree menu.
     */
    public function getTreeMenu($type = 'article', $startCategoryID = 0, $userFunc, $siteID = 0)
    {
        $treeMenu = array();
        $stmt = $this->dbh->query($this->buildQuery($type, $startCategoryID, $siteID));
        while($category = $stmt->fetch())
        {
            if(treeModel::isWechatMenu($type))
            {
                $category->children = $this->dao->select('id')->from(TABLE_CATEGORY)->where('parent')->eq($category->id)->fetchAll();
            }
            $linkHtml = call_user_func($userFunc, $category);

            if(isset($treeMenu[$category->id]) and !empty($treeMenu[$category->id]))
            {
                if(!isset($treeMenu[$category->parent])) $treeMenu[$category->parent] = '';
                $treeMenu[$category->parent] .= "<li>$linkHtml";  
                $treeMenu[$category->parent] .= "<ul>".$treeMenu[$category->id]."</ul>\n";
            }
            else
            {
                if(isset($treeMenu[$category->parent]) and !empty($treeMenu[$category->parent]))
                {
                    $treeMenu[$category->parent] .= "<li>$linkHtml\n";  
                }
                else
                {
                    $treeMenu[$category->parent] = "<li>$linkHtml\n";  
                }    
            }
            $treeMenu[$category->parent] .= "</li>\n"; 
        }
        $lastMenu = "<ul class='tree'>" . @array_pop($treeMenu) . "</ul>\n";
        return $lastMenu; 
    }

    /**
     * Create the admin link.
     * 
     * @param  int      $category 
     * @access public
     * @return string
     */
    public static function createAdminLink($category)
    {
        if($category->type == 'forum')
        {
            $categoryName = 'forum';
            $methodName   = 'boardAdmin';
            $vars         = "categoryID=$category->id";
            $linkHtml     = html::a(helper::createLink($categoryName, $methodName, $vars), $category->name, "id='category{$category->id}' target='mainwin'");
            return $linkHtml;
        }
        else
        {
            $categoryName = 'article';
            $methodName   = 'browseAdmin';
            $orderBy      = $category->type == 'article' ? 'id_desc' : '`order`';
            $vars         = "type=$category->type&&categoryID=$category->id&orderBy=$orderBy";
            $linkHtml     = html::a(helper::createLink($categoryName, $methodName, $vars), $category->name, "id='category($category->id)' target='mainwin'");
            return $linkHtml;
        }
    }

    /**
     * Create the browse link.
     * 
     * @param  int      $category 
     * @access public
     * @return string
     */
    public static function createBrowseLink($category)
    {
        $linkHtml = html::a(helper::createLink('article', 'browse', "categoryID={$category->id}", "category={$category->alias}"), $category->name, "id='category{$category->id}'");
        return $linkHtml;
    }

    /**
     * Create the product browse link.
     * 
     * @param  int      $category 
     * @access public
     * @return string
     */
    public static function createProductBrowseLink($category)
    {
        $linkHtml = html::a(helper::createLink('product', 'browse', "categoryID={$category->id}", "category={$category->alias}"), $category->name, "id='category{$category->id}'");
        return $linkHtml;
    }


    /**
     * Create the blog browse link.
     * 
     * @param  int      $category 
     * @access public
     * @return string
     */
    public static function createBlogBrowseLink($category)
    {
        $linkHtml = html::a(helper::createLink('blog', 'index', "category={$category->id}", "category={$category->alias}"), $category->name, "id='category{$category->id}'");
        return $linkHtml;
    }

    /**
     * Create the manage link.
     * 
     * @param  int         $category 
     * @access public
     * @return string
     */
    public static function createManageLink($category)
    {
        global $lang;

        /* Set the class of children link. */
        $childrenLinkClass = '';
        if($category->type == 'forum' and $category->grade == 2) $childrenLinkClass = 'hidden';

        $linkHtml  = $category->name;
        $linkHtml .= ' ' . html::a(helper::createLink('tree', 'edit',     "category={$category->id}&type={$category->type}"), $lang->tree->edit, "class='ajax'");
        $linkHtml .= ' ' . html::a(helper::createLink('tree', 'children', "type={$category->type}&category={$category->id}"), $lang->category->children, "class='$childrenLinkClass ajax'");
        $linkHtml .= ' ' . html::a(helper::createLink('tree', 'delete',   "category={$category->id}"), $lang->delete, "class='deleter'");

        return $linkHtml;
    }

    /**
     * Create the manage link for wechat menu.
     * 
     * @param  int         $category 
     * @access public
     * @return string
     */
    public static function createWechatMenuLink($category)
    {
        global $lang;

        $public = str_replace('wechat_', '', $category->type);

        $linkHtml  = $category->name;
        if($category->parent == 0)     $linkHtml .= ' ' . html::a(helper::createLink('tree', 'children', "type={$category->type}&category={$category->id}"), $lang->wechatMenu->children, "class='ajax'");
        if(empty($category->children)) $linkHtml .= ' ' . html::a(helper::createLink('wechat', 'setResponse', "public={$public}&group=menu&key=m_{$category->id}"), $lang->tree->setResponse, "class='ajax'");
        $linkHtml .= ' ' . html::a(helper::createLink('tree', 'delete',   "category={$category->id}"), $lang->delete, "class='deleter'");

        return $linkHtml;
    }

    /**
     * Update a category.
     * 
     * @param  int     $categoryID 
     * @access public
     * @return void
     */
    public function update($categoryID)
    {
        $category = fixer::input('post')->join('moderators', ',')->setDefault('readonly', 0)->get();
        $category->alias    = seo::unify($category->alias, '-');
        $category->keywords = seo::unify($category->keywords, ',');

        /* Set moderators. */
        if(!isset($category->moderators))
        {
            $category->moderators = '';
        }
        else
        {
            $category->moderators = trim($category->moderators, ',');
            $category->moderators = empty($category->moderators) ? '' : ',' . $category->moderators . ',';
        }

        /* Add id to check alias. */
        $category->id = $categoryID; 
        if(!$this->checkAlias($category)) return sprintf($this->lang->tree->aliasRepeat, $category);

        $parent = $this->getById($this->post->parent);
        $category->grade = $parent ? $parent->grade + 1 : 1;

        $this->dao->update(TABLE_CATEGORY)
            ->data($category, $skip = 'uid')
            ->autoCheck()
            ->check($this->config->tree->require->edit, 'notempty')
            ->where('id')->eq($categoryID)
            ->exec();

        $this->fixPath($category->type);

        return !dao::isError();
    }
        
    /**
     * Delete a category.
     * 
     * @param  int     $categoryID 
     * @access public
     * @return void
     */
    public function delete($categoryID, $null = null)
    {
        $category = $this->getById($categoryID);
        $family   = $this->getFamily($categoryID);

        $this->dao->update(TABLE_CATEGORY)->set('grade = grade - 1')->where('id')->in($family)->exec();                      // Update family's grade.
        $this->dao->update(TABLE_CATEGORY)->set('parent')->eq($category->parent)->where('parent')->eq($categoryID)->exec();  // Update children's parent to their grandpa.
        $this->dao->delete()->from(TABLE_CATEGORY)->where('id')->eq($categoryID)->exec();                                    // Delete my self.
        $this->fixPath($category->type);

        return !dao::isError();
    }

    /**
     * Manage children of one category.
     * 
     * @param string $type 
     * @param string $children 
     * @access public
     * @return void
     */
    public function manageChildren($type, $parent, $children)
    {
        /* Get parent. */
        $parent = $this->getByID($parent);

        /* Init the category object. */
        $category = new stdclass();
        $category->parent     = $parent ? $parent->id : 0;
        $category->grade      = $parent ? $parent->grade + 1 : 1;
        $category->type       = $type;
        $category->postedDate = helper::now();

        $i = 1;
        foreach($children as $key => $categoryName)
        {
            $alias = $this->post->alias[$key];
            $alias = seo::unify($alias, '-');
            if(empty($categoryName)) continue;

            /* First, save the child without path field. */
            $category->name  = $categoryName;
            $category->alias = $alias;
            $category->order = $this->post->maxOrder + $i * 10;
            $mode = $this->post->mode[$key];

            /* Add id to check alias. */
            $category->id = $mode == 'new' ?  0: $category->id = $key;
            if(!$this->checkAlias($category)) return sprintf($this->lang->tree->aliasRepeat, $alias);

            if($mode == 'new')
            {
                unset($category->id);
                $this->dao->insert(TABLE_CATEGORY)->data($category)->exec();

                /* After saving, update it's path. */
                $categoryID   = $this->dao->lastInsertID();
                $categoryPath = $parent ? $parent->path . $categoryID . ',' : ",$categoryID,";
                $this->dao->update(TABLE_CATEGORY)
                    ->set('path')->eq($categoryPath)
                    ->where('id')->eq($categoryID)
                    ->exec();
                $i ++;
            }
            else
            {
                $categoryID = $key;
                $this->dao->update(TABLE_CATEGORY)
                    ->set('name')->eq($categoryName)
                    ->set('alias')->eq($alias)
                    ->where('id')->eq($categoryID)
                    ->exec();
            }
        }

        return !dao::isError();
    }
    
    /**
     * Check if alias available.
     *
     * @param  object    $category 
     * @access public
     * @return void
     */
    public function checkAlias($category)
    {
        if(empty($category)) return false;
        if($category->alias == '') return true;
        if(empty($category->id)) $category->id = 0;
        if(in_array($category->type, array('article', 'product')) and strpos($this->config->tree->systemModules, ",{$category->alias},") !== false)
        {
            $this->lang->tree->aliasRepeat = $this->lang->tree->aliasConflict;
            return false;
        }

        $scope = array();
        $scope['article'] = 'article,product';
        $scope['product'] = 'article,product';
        $scope['blog']    = 'blog';
        $scope['forum']   = 'forum';

        $count = $this->dao->select('count(*) as count')->from(TABLE_CATEGORY)
            ->where('`alias`')->eq($category->alias)->andWhere('id')->ne($category->id)
            ->andWhere('type')->in($scope[$category->type])->fetch('count');
        return $count < 1;
    }

    /**
     * Fix the path, grade fields according to the id and parent fields.
     *
     * @param  string    $type 
     * @access public
     * @return void
     */
    public function fixPath($type)
    {
        /* Get all categories grouped by parent. */
        $groupCategories = $this->dao->select('id, parent')->from(TABLE_CATEGORY)
            ->where('type')->eq($type)
            ->fetchGroup('parent', 'id');
        $categories = array();

        /* Cycle the groupCategories until it has no item any more. */
        while(count($groupCategories) > 0)
        { 
            /* Record the counts before processing. */
            $oldCounts = count($groupCategories);

            foreach($groupCategories as $parentCategoryID => $childCategories)
            {
                /** 
                 * If the parentCategory doesn't exsit in the categories, skip it. 
                 * If exists, compute it's child categories. 
                 */
                if(!isset($categories[$parentCategoryID]) and $parentCategoryID != 0) continue;

                if($parentCategoryID == 0)
                {
                    $parentCategory = new stdclass();
                    $parentCategory->grade = 0;
                    $parentCategory->path  = ',';
                }
                else
                {
                    $parentCategory = $categories[$parentCategoryID];
                }

                /* Compute it's child categories. */
                foreach($childCategories as $childCategoryID => $childCategory)
                {
                    $childCategory->grade = $parentCategory->grade + 1;
                    $childCategory->path  = $parentCategory->path . $childCategory->id . ',';

                    /**
                     * Save child category to categories, 
                     * thus the child of child can compute it's grade and path.
                     */
                    $categories[$childCategoryID] = $childCategory;
                }

                /* Remove it from the groupCategories.*/
                unset($groupCategories[$parentCategoryID]);
            }

            /* If after processing, no category processed, break the cycle. */
            if(count($groupCategories) == $oldCounts) break;
        }

        /* Save categories to database. */
        foreach($categories as $category)
        {
            $this->dao->update(TABLE_CATEGORY)->data($category)
                ->where('id')->eq($category->id)
                ->exec();
        }
    }

    /**
     * Check if a category is wechatMenu by type.
     * 
     * @param  string    $type 
     * @static
     * @access public
     * @return bool
     */
    public static function isWechatMenu($type)
    {
        return substr($type, 0, 7) == 'wechat_';
    }
}

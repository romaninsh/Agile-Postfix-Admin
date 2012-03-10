<?php
include 'atk4/loader.php';

class UserList extends Grid {
	function init(){
		parent::init();
        $p=$this->add('Paginator',null,'paginator');
        $f=$this->addQuickSearch(array('name','email'));
        if($this->api->getUserLevel() > 9){
            $f=$f->addField('dropdown','domain','');
            $f->owner->search_field->template->trySet('row_class','span6');
            $f->owner->add('Order')->move($f,'first')->later();
            $f->setModel('Domain');
            $f->template->trySet('row_class','span6');
        }
		//if($this->api->getUserLevel() < 99)$this->setDomains();
	}
	function format_access($field){
		switch($this->current_row[$field]){
			case "0": 
				return $this->current_row[$field] = "User";
			case "1": 
				return $this->current_row[$field] = "No Login";
		
			case "9": 
				return $this->current_row[$field] = "Domain Admin";
			
			case "99": 
				return $this->current_row[$field] = "Super Admin";
			
			default:
				return $this->current_row[$field] = "Who is there? Get out!!!";
		}
	}
	function setDomains(){
		$domains = split(';', $this->api->getUserDomains());
		$where = "";
		foreach($domains as $domain){
			if($where != "")$where .= ' or ';
			$where .= "email like '%$domain%'";
		}
		$this->dq->where($where);
	}
}

class UserEditForm extends Form {
	
	function init(){
		parent::init();
		$this
			->addField('line', 'email', 'E-Mail')
			->addField('password', 'clear', 'Password')
			->addField('line', 'name', 'Full Name')
			->addField('line', 'relocated_to', 'Relocate to')
			->addField('line', 'forward_to', 'Forward to')
			->addField('line', 'cc_to', 'Send copy to')
		;
		if($this->api->getUserLevel() == 99){
			$this
				->addField('text', 'domains', 'Trusted domains')
				->addField('dropdown', 'access_level', 'Access Level')
					->setValueList(array(0=>'Self only', 9=>'Maintain', 99=>'Admin'))
			;
		}
		$this->addSubmit('Save');
		if($this->api->getUserLevel() == 99&&$_GET['id']!=''){
			$this->addSubmit('Delete');
		}

		$this
            ->setSource('users')
            ->addConditionFromGET('id');
	}
	function submitted(){
		if(!parent::submitted())return false;
		if($this->isClicked('Save')){
			//setting additional fields
			$this->dq->set('relocated', $this->get('relocated_to')?'Y':'N');
			$this->dq->set('forward', $this->get('forward_to')||$this->get('cc_to')?'Y':'N');
            if(!$this->update())throw new BaseException("Cannot save record");
		}elseif($this->isClicked('Delete')){
            if(!$this->dq->do_delete())throw new BaseException("Cannot delete record");
		}else return false;
        $this->api->redirect('UserManagement');
	}
}
class Model_User extends Model_Table {
    public $table='users';
    function init(){
        parent::init();
        $this->addField('name');
        $this->addField('email');
        $this->addField('clear')->type('password');

        $this->addExpression('domain')->set('substring_index(email,"@",-1)');

        $this->addField('postfix')->type('boolean')->enum(array('y','n'));
        $this->addCondition('postfix',true);

        $this->addField('cc_to')->caption('CC incoming email');
        $this->addField('forward')->caption('redirect email')->type('boolean')->enum(array('Y','N'));
        $this->addField('forward_to')->caption('redirect to')->type('text');

        $this->addField('access_level')->caption('Access Level')->display(array('grid'=>'access'));
        $this->addField('domains')->caption('Administrating Domains');

    }
}
class Model_EditableUser extends Model_User {
    function init(){
        parent::init();
        if($this->api->auth){
            $al=$this->api->auth->get('access_level');

            if($al==0){
                $this->addCondition('email',$this->api->auth->get('email'));
                $this->getField('email')->readonly(true);
                $this->getField('access_level')->destroy();
                $this->getField('domains')->destroy();
            }
            elseif($al==9){
                $d=explode('@',$this->api->auth->get('email'));
                $this->addCondition('domain',$d[1]);
            }elseif($al!=99){
                throw $this->exception('Unknown user level')
                    ->addMoreInfo('users level',$al);
            }
        }
    }
}
class Model_Domain extends Model_Table {
    public $table='users';
    public $id_field='name';
    function init(){
        parent::init();
        $this->getField('name')->destroy();
        $this->addExpression('name')->set('substring_index(email,"@",-1)');
        $this->addField('postfix');
        $this->dsql->group($this->dsql->expr('substring_index(email,"@",-1)'));
        $this->addCondition('postfix','y');
    }

}
class ApiMailSql extends ApiAdmin {
	public $auth;
	public $logger;
	
    public $apinfo=array(
            'version'=>'0.96',
            'name'=>'MailSql Admin'
            );
	
	function init(){
		$this->readConfig('config.php');
		parent::init();
        $this->add('jUI');
		$this->dbConnect();
		//$this->api->add('VersionControl');
		$this->template->trySet('page_title', $this->apinfo['name']);
		$this->auth = $this->api->add('BasicAuth');
        $this->auth->setModel('User','email','clear');
             #->setNoCrypt();
            
        $this->auth->check();
        $menu = $this->add('Menu', null, 'Menu');
        $menu
            ->addMenuItem('UserManagement','User Management')
            //->addMenuItem('Postfix Configuration')
            ->addMenuItem('About')
            ->addMenuItem('Logout')
            ;
	}
	function page_Index(){
            $this->redirect('UserManagement');
	}
	function page_Logout(){
		$this->auth->logout();
	}
    function page_UserManagement($p){
    		if($this->getUserLevel() > 0){
                $userlist = $this->add('CRUD',array('grid_class'=>'UserList'));
                $userlist->setModel('EditableUser',null,array('email','name','forward','forward_to','access_level','domains'));
    		}else{
                $f=$this->add('Form');
                $f->addSubmit('Update');
                $f->setModel('EditableUser')->loadAny();
                if($f->isSubmitted()){
                    $f->update();
                    $f->js()->univ()->successMessage('Your settings have been updated')->execute();
                }
    		}
    }
	function page_PostfixConfiguration($p){
		$p->add('NotImplemented', null, 'Content');
	}
	function getUserLevel(){
        return $this->api->auth->get('access_level');
	}
}

$api = new ApiMailSql('MailSQL');
//$api->info('test');
$api->main();

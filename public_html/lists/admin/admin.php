<?php

require_once dirname(__FILE__).'/accesscheck.php';

$start = sprintf('%d', !empty($_GET['start']) ? $_GET['start'] : 0);
echo PageLinkActionButton('admins', s('List of Administrators'), "start=$start");

require dirname(__FILE__).'/structure.php';

$struct = $DBstruct['admin'];
$id = !empty($_REQUEST['id']) ? sprintf('%d', $_REQUEST['id']) : 0;
$find = isset($_REQUEST['find']) ? $_REQUEST['find'] : '';
$start = isset($_GET['start']) ? sprintf('%d', $_GET['start']) : 0;

echo '<hr /><br />';

if (!isSuperUser()) {
    echo Error(s('No Access'));
    return;
}
$accesslevel = 'all';

if (!empty($_POST['change'])) {
    if (!verifyToken()) { //# csrf check, should be added in more places
        echo Error(s('No Access'));

        return;
    }
    if (empty($_POST['id'])) {
        // Check if fields login name and email are present
        if (!is_null($_POST['loginname']) && $_POST['loginname'] !== '' && !is_null($_POST['email']) && $_POST['email'] !== '') {
            if (validateEmail($_POST['email'])) {
                // new one
                $result = Sql_query(sprintf('SELECT count(*) FROM %s WHERE namelc="%s" OR email="%s"',
                    $tables['admin'], strtolower(normalize($_POST['loginname'])),
                    strtolower(normalize($_POST['email']))));
                $totalres = Sql_fetch_Row($result);
                $total = $totalres[0];
                if (!$total) {

                    if (isset($_REQUEST['adminpassword'])) {
                        $adminpass = $_REQUEST['adminpassword'];
                    } else {
                        $adminpass = random_bytes(32);
                    }
                    Sql_Query(sprintf('insert into %s (loginname,namelc,password,email,created) values("%s","%s","%s","%s",now())',
                        $tables['admin'], strtolower(normalize($_POST['loginname'])),
                        strtolower(normalize($_POST['loginname'])), encryptPass($adminpass),
                        sql_escape($_POST['email'])));
                    $id = Sql_Insert_Id($tables['admin'], 'id');
                } else {
                    $id = 0;
                }
            } else {
                //# email doesn't validate
                $id = 0;
            }
        } else {
            $id = 0;
        }
    } else {
        $id = sprintf('%d', $_POST['id']);
        //#17388 - disallow changing an admin email to an already existing one
        if (!empty($_POST['email'])) {
            $exists = Sql_Fetch_Row_Query(sprintf('select id from %s where email = "%s"', $tables['admin'],
                sql_escape($_POST['email'])));
            if (!empty($exists[0]) && $exists[0] != $id) {
                Error(s('Cannot save admin, that email address already exists for another admin'));
                echo PageLinkButton('admin&id='.$id, s('Back to edit admin'));

                return;
            }
        }
    }

    if ($id) {
        echo '<div class="actionresult">';
        reset($struct);
        $_POST['email'] = htmlspecialchars(strip_tags($_POST['email']));
        foreach ($struct as $key => $val) {
            $a = $b = '';
            if (strstr($val[1], ':')) {
                list($a, $b) = explode(':', $val[1]);
            }
            if ($a != 'sys' && isset($_POST[$key])) {
                Sql_Query("update {$tables['admin']} set $key = \"".addslashes($_POST[$key])."\" where id = $id");
            }
        }
        if (!empty($_POST['updatepassword'])) {
            //Send token email.
            echo sendAdminPasswordToken($id).'<br/>';

        }
        if (isset($_POST['attribute']) && is_array($_POST['attribute'])) {
            foreach ($_POST['attribute'] as $key => $val) {
                Sql_Query(sprintf('replace into %s (adminid,adminattributeid,value)
          values(%d,%d,"%s")', $tables['admin_attribute'], $id, $key, addslashes($val)));
            }
        }
        $privs = array(
            'subscribers' => !empty($_POST['subscribers']),
            'campaigns'   => !empty($_POST['campaigns']),
            'statistics'  => !empty($_POST['statistics']),
            'settings'    => !empty($_POST['settings']),
        );
        Sql_Query(sprintf('update %s set modifiedby = "%s", privileges = "%s" where id = %d',
            $GLOBALS['tables']['admin'], adminName($_SESSION['logindetails']['id']), sql_escape(serialize($privs)),
            $id));

        echo s('Changes saved');
        echo '</div>';
    } else {
        Error(s('Error adding new admin, login name and/or email not inserted, email not valid or admin already exists'));
    }
}

if (!empty($_GET['delete'])) {
    $delete = sprintf('%d', $_GET['delete']);
    // delete the index in delete
    echo s('Deleting')." $delete ..\n";
    if ($delete != $_SESSION['logindetails']['id']) {
        $adminName = $admin_auth->adminName($delete);
        $deleterName = $admin_auth->adminName($_SESSION['logindetails']['id']);
        logEvent(s('Administrator %s deleted by %s', $adminName, $deleterName));
        Sql_query(sprintf('delete from %s where id = %d', $GLOBALS['tables']['admin'], $delete));
        Sql_query(sprintf('delete from %s where adminid = %d', $GLOBALS['tables']['admin_attribute'], $delete));
        echo '..'.s('Done');
    } else {
        echo '..'.s('Failed, you cannot delete yourself');
    }
    echo "<br /><hr/><br />\n";
}

echo '<div class="panel">';

if ($id) {
    $addAdmin = false;
    echo '<h3>'.s('Edit Administrator').': ';
    $result = Sql_query("SELECT * FROM {$tables['admin']} where id = $id");
    $data = sql_fetch_assoc($result);
    echo htmlentities($data['loginname']).'</h3>';
    if ($data['id'] != $_SESSION['logindetails']['id'] && $accesslevel == 'all') {
        printf("<br /><a href=\"javascript:deleteRec('%s');\">Delete</a> %s\n", PageURL2('admin', '', "delete=$id"),
            htmlentities($data['loginname']));
    }
} else {
    $addAdmin = true;
    $data = array();
    echo '<h3>'.s('Add a new Administrator').'</h3>';
}

echo '<div class="content">';
//var_dump($data);

echo formStart(' class="adminAdd"');
printf('<input type="hidden" name="id" value="%d" /><table class="adminDetails"  border="1">', $id);

if (isset($data['privileges'])) {
    $privileges = unserialize($data['privileges']);
} else {
    $privileges = array('subscribers' => 0, 'campaigns' => 0, 'statistics' => 0, 'settings' => 0);
}

reset($struct);
foreach ($struct as $key => $val) {
    $a = $b = '';
    if (empty($data[$key])) {
        $data[$key] = '';
    }
    if (strstr($val[1], ':')) {
        list($a, $b) = explode(':', $val[1]);
    }
    if ($a == 'sys') {
        switch ($b) {
            case 'Privileges':
                break;
            case 'Password':
                //If key is 'password' and the passwords are encrypted, locate two radio buttons to allow an update.
                $changeAdminPass = !empty($_SESSION['firstinstall']);
                if ($addAdmin===true){

                    echo ' <tr>
      <td>'.s('Choose how to set password').'</td>
      <td>
          <input type="radio" id="passwordoption1" name="passwordoption" value="1"  checked="checked">'.s('Send email').'
          <input type="radio" id= "passwordoption0" name="passwordoption" value="0"   >'.s('Create password').'
      </td>
  </tr>

  <tr id="passrow">
        <td>
            <label for="adminpassword">'.s('Create password').'</label>
        </td>
        <td>
            <input type="password" name="adminpassword" id="adminpassword" value="" >
            <span id= "shortpassword">'.s('Password must be at least 8 characters').'</span>
        </td>
    </tr>

    <tr id="confirmrow">
        <td>
            <label for="confirmpassword">'.s('Confirm password').'</label>
        </td>
        <td>
            <input type="password" name="confirmpassword" id="confirmpassword" value="">
            <span id= "notmatching">'.s('Not matching').'</span>
        </td>
    </tr>';


                }

                if ($changeAdminPass) {
                    $checkNo = '';
                    $checkYes = 'checked="checked"';
                } else {
                    $checkYes = '';
                    $checkNo = 'checked="checked"';
                }
                if ($addAdmin===false) {
                    printf('<tr><td>%s (%s)</td><td>%s<input type="radio" name="updatepassword" value="1" %s>%s</input>
                               <input type="radio" name="updatepassword" value="0" %s>%s</input></td></tr>
',
                        s('Password'), s('hidden'),
                        s('Update it?'),
                        $checkYes, s('Yes'), $checkNo, s('No'));
                }
                break;
            default:
                if ($addAdmin) {
                    break;
                }

                switch ($key) {
                    case 'created':
                        $value = formatDateTime($data[$key]);
                        break;
                    case 'modified':
                        $value = formatDateTime($data[$key]);
                        break;
                    case 'passwordchanged':
                        $value = formatDate($data[$key]);
                        break;
                    default:
                        $value = htmlentities($data[$key]);
                }
                printf('<tr><td>%s</td><td>%s</td></tr>', s($b), $value);
        }
    } elseif ($key == 'loginname' && $data[$key] == 'admin') {
        printf('<tr><td>'.s('Login Name').'</td><td>admin</td>');
        echo '<td><input type="hidden" name="loginname" value="admin" /></td></tr>';
    } elseif ($key == 'superuser' || $key == 'disabled') {
        if ($accesslevel == 'all') {
            //If key is 'superuser' or 'disable' locate a boolean combo box.
            printf('<tr><td>%s</td><td>', s($val[1]));
            printf('<select name="%s" size="1">', $key);
            echo '<option value="1" '.(!empty($data[$key]) ? ' selected="selected"' : '').'>'.s('Yes').'</option>';
            echo '<option value="0" '.(empty($data[$key]) ? ' selected="selected"' : '').'>'.s('No').'</option></select>';
            echo '</td></tr>'."\n";
        }
    } elseif (!empty($val[1]) && !strpos($key, '_')) {

        printf('<tr><td>%s</td><td><input type="text" name="%s" value="%s" size="30" /></td></tr>'."\n",
            s($val[1]), $key, htmlspecialchars(stripslashes($data[$key])));
    }
}
$res = Sql_Query("select
  {$tables['adminattribute']}.id,
  {$tables['adminattribute']}.name,
  {$tables['adminattribute']}.type,
  {$tables['adminattribute']}.tablename from
  {$tables['adminattribute']}
  order by {$tables['adminattribute']}.listorder");
while ($row = Sql_fetch_array($res)) {
    if ($id) {
        $val_req = Sql_Fetch_Row_Query("select value from {$tables['admin_attribute']}
      where adminid = $id and adminattributeid = $row[id]");
        if (isset($val_req[0])) {
          $row['value'] = $val_req[0];
        } else {
          $row['value'] = '';
        }
    } else {
        $row['value'] = '';
    }

    if ($row['type'] == 'checkbox') { // admins can only have hidden or textline
        $checked_index_req = Sql_Fetch_Row_Query("select id from $table_prefix".'adminattr_'.$row['tablename'].' where name = "Checked"');
        $checked_index = $checked_index_req[0];
        $checked = $checked_index == $row['value'] ? 'checked="checked"' : '';
        printf('<tr><td>%s</td><td><input class="attributeinput" type="hidden" name="cbattribute[]" value="%d" />

<input class="attributeinput" type="checkbox" name="attribute[%d]" value="Checked" %s /></td></tr>' ."\n",
            htmlentities($row['name']), $row['id'], $row['id'], $checked);
    } else {
        printf('<tr><td>%s</td><td><input class="attributeinput" type="text" name="attribute[%d]" value="%s" size="30" /></td></tr>'."\n",
            htmlentities($row['name']), $row['id'], htmlentities($row['value']));
    }
}

echo '<tr><td colspan="2">';

$checked = array();
foreach ($privileges as $section => $allowed) {
    if (!empty($allowed)) {
        $checked[$section] = 'checked="checked"';
    } else {
        $checked[$section] = '';
    }
}

echo '<div id="privileges">
' .s('Privileges').':
<label for="subscribers"><input type="checkbox" name="subscribers" ' .$checked['subscribers'].' />'.s('Manage subscribers').'</label>
<label for="campaigns"><input type="checkbox" name="campaigns" ' .$checked['campaigns'].'/>'.s('Send Campaigns').'</label>
<label for="statistics"><input type="checkbox" name="statistics" ' .$checked['statistics'].'/>'.s('View Statistics').'</label>
<label for="settings"><input type="checkbox" name="settings" ' .$checked['settings'].'/>'.s('Change Settings').'</label>
</div>';
echo '</td></tr>';
if (!empty($_POST['passwordoption'])) {
    echo sendAdminPasswordToken($id).'<br/>';
}
echo '<tr><td colspan="2"><input class="submit" type="submit" name="change" id ="savechanges"  value="' . s('Save Changes') . '" /></td></tr></table>';

echo '</div>'; // content
echo '</div>'; // panel

echo '</form>';

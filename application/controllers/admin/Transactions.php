<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

class Transactions extends Admin_Controller
{

    public function __construct()
    {
        parent::__construct();
        $this->load->model('transactions_model');

        $this->load->helper('ckeditor');
        $this->data['ckeditor'] = array(
            'id' => 'ck_editor',
            'path' => 'asset/js/ckeditor',
            'config' => array(
                'toolbar' => "Full",
                'width' => "99.8%",
                'height' => "400px"
            )
        );
    }

    public function deposit($id = NULL)
    {
        $data['title'] = lang('all_deposit');
        // get permission user by menu id
        $data['permission_user'] = $this->transactions_model->all_permission_user('30');
        $data['all_deposit_info'] = $this->transactions_model->get_permission('tbl_transactions');
        if (!empty($id)) {
            $deposit_info = $this->transactions_model->check_by(array('transactions_id' => $id), 'tbl_transactions');
            if (!empty($deposit_info))
                $can_edit = $this->transactions_model->can_action('tbl_transactions', 'edit', array('transactions_id' => $id));
            if (!empty($can_edit)) {
                $data['deposit_info'] = $deposit_info;
            }
            $data['active'] = 2;
        } else {
            $data['active'] = 1;
        }
        $data['subview'] = $this->load->view('admin/transactions/deposit', $data, TRUE);
        $this->load->view('admin/_layout_main', $data); //page load
    }

    public function import($type)
    {
        if ($type == 'Income') {
            $header = lang('deposit');
        } else {
            $header = lang('expense');
        }
        $data['title'] = lang('import') . ' ' . $header;
        $data['permission_user'] = $this->transactions_model->all_permission_user('30');
        $data['type'] = $type;
        $data['subview'] = $this->load->view('admin/transactions/import', $data, TRUE);
        $this->load->view('admin/_layout_main', $data); //page load
    }

    public function save_imported()
    {
        //load the excel library
        $this->load->library('excel');
        ob_start();
        $file = $_FILES["upload_file"]["tmp_name"];
        if (!empty($file)) {
            $valid = false;
            $types = array('Excel2007', 'Excel5');
            foreach ($types as $type) {
                $reader = PHPExcel_IOFactory::createReader($type);
                if ($reader->canRead($file)) {
                    $valid = true;
                }
            }
            if (!empty($valid)) {
                try {
                    $objPHPExcel = PHPExcel_IOFactory::load($file);
                } catch (Exception $e) {
                    die("Error loading file :" . $e->getMessage());
                }
                //All data from excel
                $sheetData = $objPHPExcel->getActiveSheet()->toArray(null, true, true, true);


                for ($x = 2; $x <= count($sheetData); $x++) {

                    // **********************
                    // Save Into leads table
                    // **********************

                    $data = $this->transactions_model->array_from_post(array('account_id', 'type', 'category_id', 'paid_by', 'payment_methods_id'));

                    $date = date('Y-m-d', strtotime($sheetData[$x]["A"]));

                    $data['date'] = trim($date);
                    $data['amount'] = trim($sheetData[$x]["B"]);

                    $account_info = $this->transactions_model->check_by(array('account_id' => $data['account_id']), 'tbl_accounts');
                    if ($data['type'] == 'Income') {
                        $ac_data['balance'] = $account_info->balance + $data['amount'];
                    } else {
                        $ac_data['balance'] = $account_info->balance - $data['amount'];
                    }
                    $this->transactions_model->_table_name = "tbl_accounts"; //table name
                    $this->transactions_model->_primary_key = "account_id";
                    $this->transactions_model->save($ac_data, $account_info->account_id);

                    $data['notes'] = trim($sheetData[$x]["C"]);
                    $data['reference'] = trim($sheetData[$x]["D"]);

                    if (!empty($_FILES['attachement']['name']['0'])) {
                        $old_path_info = $this->input->post('upload_path');
                        if (!empty($old_path_info)) {
                            foreach ($old_path_info as $old_path) {
                                unlink($old_path);
                            }
                        }
                        $mul_val = $this->transactions_model->multi_uploadAllType('attachement');
                        $data['attachement'] = json_encode($mul_val);
                    }

                    $permission = $this->input->post('permission', true);
                    if (!empty($permission)) {
                        if ($permission == 'everyone') {
                            $assigned = 'all';
                        } else {
                            $assigned_to = $this->transactions_model->array_from_post(array('assigned_to'));
                            if (!empty($assigned_to['assigned_to'])) {
                                foreach ($assigned_to['assigned_to'] as $assign_user) {
                                    $assigned[$assign_user] = $this->input->post('action_' . $assign_user, true);
                                }
                            }
                        }
                        if ($assigned != 'all') {
                            $assigned = json_encode($assigned);
                        }
                        $data['permission'] = $assigned;
                    }
                    $this->transactions_model->_table_name = "tbl_transactions"; //table name
                    $this->transactions_model->_primary_key = "transactions_id";
                    $this->transactions_model->save($data);
                }
                $type = 'success';
                if ($data['type'] == 'Income') {
                    $message = lang('save_new_deposit');
                    $redirect = 'deposit';
                } else {
                    $message = lang('save_new_expense');
                    $redirect = 'expense';
                }
            } else {
                $type = 'error';
                $message = "Sorry your uploaded file type not allowed ! please upload XLS/CSV File ";
            }
        } else {
            $type = 'error';
            $message = "You did not Select File! please upload XLS/CSV File ";
        }
        set_message($type, $message);
        redirect($_SERVER['HTTP_REFERER']);

    }

    public function save_deposit($id = NULL)
    {


        $data = $this->transactions_model->array_from_post(array('date', 'notes', 'category_id', 'paid_by', 'payment_methods_id', 'reference'));
        $data['type'] = 'Income';
        if (!empty($id)) {
            $account_id = $this->input->post('old_account_id', TRUE);
        } else {
            $data['account_id'] = $this->input->post('account_id', TRUE);
            $account_id = $data['account_id'];
            $data['amount'] = $this->input->post('amount', TRUE);
            $data['credit'] = $this->input->post('amount', TRUE);

            $account_info = $this->transactions_model->check_by(array('account_id' => $account_id), 'tbl_accounts');

            $ac_data['balance'] = $account_info->balance + $data['amount'];
            $this->transactions_model->_table_name = "tbl_accounts"; //table name
            $this->transactions_model->_primary_key = "account_id";
            $this->transactions_model->save($ac_data, $account_info->account_id);
        }

        $aaccount_info = $this->transactions_model->check_by(array('account_id' => $account_id), 'tbl_accounts');
        $data['total_balance'] = $aaccount_info->balance;

        $fileName = $this->input->post('fileName');
        $path = $this->input->post('path');
        $fullPath = $this->input->post('fullPath');
        $size = $this->input->post('size');
        $is_image = $this->input->post('is_image');

        if (!empty($fileName)) {
            foreach ($fileName as $key => $name) {
                $old['fileName'] = $name;
                $old['path'] = $path[$key];
                $old['fullPath'] = $fullPath[$key];
                $old['size'] = $size[$key];
                $old['is_image'] = $is_image[$key];
                $result[] = $old;
            }
            $data['attachement'] = json_encode($result);
        }

        if (!empty($_FILES['attachement']['name']['0'])) {
            $old_path_info = $this->input->post('upload_path');
            if (!empty($old_path_info)) {
                foreach ($old_path_info as $old_path) {
                    unlink($old_path);
                }
            }
            $mul_val = $this->transactions_model->multi_uploadAllType('attachement');
            $data['attachement'] = json_encode($mul_val);
        }
        if (!empty($result) && !empty($mul_val)) {
            $file = array_merge($result, $mul_val);
            $data['attachement'] = json_encode($file);
        }

        $permission = $this->input->post('permission', true);
        if (!empty($permission)) {
            if ($permission == 'everyone') {
                $assigned = 'all';
            } else {
                $assigned_to = $this->transactions_model->array_from_post(array('assigned_to'));
                if (!empty($assigned_to['assigned_to'])) {
                    foreach ($assigned_to['assigned_to'] as $assign_user) {
                        $assigned[$assign_user] = $this->input->post('action_' . $assign_user, true);
                    }
                }
            }
            if ($assigned != 'all') {
                $assigned = json_encode($assigned);
            }
            $data['permission'] = $assigned;
        }


        $this->transactions_model->_table_name = "tbl_transactions"; //table name
        $this->transactions_model->_primary_key = "transactions_id";


        if (!empty($id)) {
            $this->transactions_model->save($data, $id);
            $activity = ('activity_update_deposit');
            $msg = lang('update_a_deposit');
            save_custom_field(1, $id);
        } else {
            $id = $this->transactions_model->save($data);
            $activity = ('activity_new_deposit');
            $msg = lang('save_new_deposit');
        }
        save_custom_field(1, $id);

        // save into activities
        $activities = array(
            'user' => $this->session->userdata('user_id'),
            'module' => 'transactions',
            'module_field_id' => $id,
            'activity' => $activity,
            'icon' => 'fa-coffee',
            'value1' => $aaccount_info->account_name,
        );
        // Update into tbl_project
        $this->transactions_model->_table_name = "tbl_activities"; //table name
        $this->transactions_model->_primary_key = "activities_id";
        $this->transactions_model->save($activities);
        $type = 'success';
        $message = $msg;
        set_message($type, $message);
        redirect('admin/transactions/deposit/');
    }

    public function delete_deposit($id)
    {
        $deposit_info = $this->transactions_model->check_by(array('transactions_id' => $id), 'tbl_transactions');
        if (!empty($deposit_info)) {
            $can_delete = $this->transactions_model->can_action('tbl_transactions', 'delete', array('transactions_id' => $id));
            if (!empty($can_delete)) {
                $account_info = $this->transactions_model->check_by(array('account_id' => $deposit_info->account_id), 'tbl_accounts');

                $ac_data['balance'] = $account_info->balance - $deposit_info->amount;
                $this->transactions_model->_table_name = "tbl_accounts"; //table name
                $this->transactions_model->_primary_key = "account_id";
                $this->transactions_model->save($ac_data, $account_info->account_id);

                $activity = ('activity_delete_deposit');
                $msg = lang('delete_deposit');
                // save into activities
                $activities = array(
                    'user' => $this->session->userdata('user_id'),
                    'module' => 'transactions',
                    'module_field_id' => $id,
                    'activity' => $activity,
                    'icon' => 'fa-coffee',
                    'value1' => $account_info->account_name,
                    'value2' => $deposit_info->amount,
                );
                // Update into tbl_project
                $this->transactions_model->_table_name = "tbl_activities"; //table name
                $this->transactions_model->_primary_key = "activities_id";
                $this->transactions_model->save($activities);

                $this->transactions_model->_table_name = "tbl_transactions"; //table name
                $this->transactions_model->_primary_key = "transactions_id";
                $this->transactions_model->delete($id);

                $type = 'success';
            } else {
                $type = 'error';
                $msg = lang('there_in_no_value');
            }
        } else {
            $type = 'error';
            $msg = lang('there_in_no_value');
        }
        $message = $msg;
        set_message($type, $message);
        redirect('admin/transactions/deposit');
    }

    public function expense($id = NULL)
    {
        $data['title'] = lang('all_expense');
        // get permission user by menu id
        $data['permission_user'] = $this->transactions_model->all_permission_user('31');
        $data['all_expense_info'] = $this->transactions_model->get_permission('tbl_transactions');

        if (!empty($id)) {
            if ($id != 'project_expense') {
                $expense_info = $this->transactions_model->check_by(array('transactions_id' => $id), 'tbl_transactions');
                $can_edit = $this->transactions_model->can_action('tbl_transactions', 'edit', array('transactions_id' => $id));
                if (!empty($expense_info) && !empty($can_edit)) {
                    $data['expense_info'] = $expense_info;
                }
            }
            $data['active'] = 2;
        } else {
            $data['active'] = 1;
        }
        $data['subview'] = $this->load->view('admin/transactions/expense', $data, TRUE);
        $this->load->view('admin/_layout_main', $data); //page load
    }

    public function save_expense($id = NULL)
    {
        $data = $this->transactions_model->array_from_post(array('date', 'notes', 'category_id', 'paid_by', 'payment_methods_id', 'reference', 'project_id', 'client_visible'));

        $data['type'] = 'Expense';

        $data['account_id'] = $this->input->post('account_id', TRUE);

        $account_info = $this->transactions_model->check_by(array('account_id' => $data['account_id']), 'tbl_accounts');

        $data['amount'] = $this->input->post('amount', TRUE);

        if (!empty($data['amount'])) {
            if (!empty($account_info->balance) && $account_info->balance < $data['amount']) {
                $type = 'error';
                $msg = lang('account_limite_exceed') . "<strong style='color:#000'>" . $account_info->balance . "</strong> !";
            } else {

                $check_head = $this->db->where('department_head_id', $this->session->userdata('user_id'))->get('tbl_departments')->row();
                $role = $this->session->userdata('user_type');
                if ($role == 1 || !empty($check_head)) {
                    if (!empty($id)) {
                        $data['account_id'] = $this->input->post('old_account_id', TRUE);
                    } else {
                        $data['amount'] = $this->input->post('amount', TRUE);
                        $data['debit'] = $this->input->post('amount', TRUE);

                        $ac_data['balance'] = $account_info->balance - $data['amount'];
                        $this->transactions_model->_table_name = "tbl_accounts"; //table name
                        $this->transactions_model->_primary_key = "account_id";
                        $this->transactions_model->save($ac_data, $account_info->account_id);
                    }

                    $aaccount_info = $this->transactions_model->check_by(array('account_id' => $data['account_id']), 'tbl_accounts');

                    $data['total_balance'] = $aaccount_info->balance;
                    $data['status'] = 'paid';
                }

                $fileName = $this->input->post('fileName');
                $path = $this->input->post('path');
                $fullPath = $this->input->post('fullPath');
                $size = $this->input->post('size');
                $is_image = $this->input->post('is_image');

                if (!empty($fileName)) {
                    foreach ($fileName as $key => $name) {
                        $old['fileName'] = $name;
                        $old['path'] = $path[$key];
                        $old['fullPath'] = $fullPath[$key];
                        $old['size'] = $size[$key];
                        $old['is_image'] = $is_image[$key];
                        $result[] = $old;
                    }
                    $data['attachement'] = json_encode($result);
                }

                if (!empty($_FILES['attachement']['name']['0'])) {
                    $old_path_info = $this->input->post('upload_path');
                    if (!empty($old_path_info)) {
                        foreach ($old_path_info as $old_path) {
                            unlink($old_path);
                        }
                    }
                    $mul_val = $this->transactions_model->multi_uploadAllType('attachement');
                    $data['attachement'] = json_encode($mul_val);
                }
                if (!empty($result) && !empty($mul_val)) {
                    $file = array_merge($result, $mul_val);
                    $data['attachement'] = json_encode($file);
                }

                $permission = $this->input->post('permission', true);
                if (!empty($permission)) {
                    if ($permission == 'everyone') {
                        $assigned = 'all';
                    } else {
                        $assigned_to = $this->transactions_model->array_from_post(array('assigned_to'));
                        if (!empty($assigned_to['assigned_to'])) {
                            foreach ($assigned_to['assigned_to'] as $assign_user) {
                                $assigned[$assign_user] = $this->input->post('action_' . $assign_user, true);
                            }
                        }
                    }
                    if ($assigned != 'all') {
                        $assigned = json_encode($assigned);
                    }
                    $data['permission'] = $assigned;
                } else {
                    set_message('error', lang('assigned_to') . ' Field is required');
                    redirect($_SERVER['HTTP_REFERER']);
                }


                $this->transactions_model->_table_name = "tbl_transactions"; //table name
                $this->transactions_model->_primary_key = "transactions_id";


                if (!empty($id)) {
                    $this->transactions_model->save($data, $id);
                    $activity = ('activity_update_expense');
                    $msg = lang('update_a_expense');
                } else {
                    $data['added_by'] = $this->session->userdata('user_id');

                    $id = $this->transactions_model->save($data);
                    $activity = ('activity_new_expense');
                    $msg = lang('save_new_expense');
                }
                save_custom_field(2, $id);
                // save into activities
                $activities = array(
                    'user' => $this->session->userdata('user_id'),
                    'module' => 'transactions',
                    'module_field_id' => $id,
                    'activity' => $activity,
                    'icon' => 'fa-coffee',
                    'value1' => $account_info->account_name,
                    'value2' => $data['amount'],
                );
                // Update into tbl_project
                $this->transactions_model->_table_name = "tbl_activities"; //table name
                $this->transactions_model->_primary_key = "activities_id";
                $this->transactions_model->save($activities);
                $type = 'success';
                if ($role == 3 && empty($check_head)) {
                    $this->expense_request_email($data, $id);
                }
            }
        } else {
            $type = 'error';
            $msg = 'please enter the amount';
        }
        $message = $msg;
        set_message($type, $message);
        if (!empty($data['project_id'])) {
            redirect('admin/projects/project_details/' . $data['project_id'] . '/' . '10');
        } else {
            redirect('admin/transactions/expense');
        }

    }

    function expense_request_email($data, $id)
    {
        // get departments head user id
        $designation_info = $this->transactions_model->check_by(array('designations_id' => $this->session->userdata('designations_id')), 'tbl_designations');
        // get departments head by departments id
        $dept_head = $this->transactions_model->check_by(array('departments_id' => $designation_info->departments_id), 'tbl_departments');
        $all_admin = $this->db->where('role_id', 1)->get('tbl_users')->result();
        $head = $this->db->where('user_id', $dept_head->department_head_id)->get('tbl_users')->row();

        if (!empty($dept_head->department_head_id) || !empty($all_admin)) {
            $expense_email = config_item('expense_email');
            if (!empty($expense_email) && $expense_email == 1) {
                $email_template = $this->transactions_model->check_by(array('email_group' => 'expense_request_email'), 'tbl_email_templates');

                $message = $email_template->template_body;
                $subject = $email_template->subject;
                $username = str_replace("{NAME}", $this->session->userdata('name'), $message);
                $amount = str_replace("{AMOUNT}", $data['amount'], $username);
                $Link = str_replace("{URL}", base_url() . 'admin/transactions/expense/view_details/' . $id, $amount);
                $message = str_replace("{SITE_NAME}", config_item('company_name'), $Link);
                $data['message'] = $message;
                $message = $this->load->view('email_template', $data, TRUE);

                $params['subject'] = $subject;
                $params['message'] = $message;
                $params['resourceed_file'] = '';
                if (!empty($all_admin)) {
                    foreach ($all_admin as $v_admin) {
                        $params['recipient'] = $v_admin->email;
                        $this->transactions_model->send_email($params);
                        if (!empty($dept_head->department_head_id)) {
                            if ($dept_head->department_head_id == $v_admin->user_id) {
                                $already_send = 1;
                            }
                        }
                    }
                }
                if (empty($already_send)) {
                    $params['recipient'] = $head->email;
                    $this->transactions_model->send_email($params);
                }

            }
        }
    }

    public function delete_expense($id)
    {
        $expense_info = $this->transactions_model->check_by(array('transactions_id' => $id), 'tbl_transactions');
        if (!empty($expense_info))
            $can_delete = $this->transactions_model->can_action('tbl_transactions', 'delete', array('transactions_id' => $id));
        if (!empty($can_delete)) {
            $account_info = $this->transactions_model->check_by(array('account_id' => $expense_info->account_id), 'tbl_accounts');

            $ac_data['balance'] = $account_info->balance + $expense_info->amount;
            $this->transactions_model->_table_name = "tbl_accounts"; //table name
            $this->transactions_model->_primary_key = "account_id";
            $this->transactions_model->save($ac_data, $account_info->account_id);

            $activity = ('activity_delete_expense');
            $msg = lang('delete_expense');
            // save into activities
            $activities = array(
                'user' => $this->session->userdata('user_id'),
                'module' => 'transactions',
                'module_field_id' => $id,
                'activity' => $activity,
                'icon' => 'fa-coffee',
                'value1' => $account_info->account_name,
                'value2' => $expense_info->amount,
            );
            // Update into tbl_project
            $this->transactions_model->_table_name = "tbl_activities"; //table name
            $this->transactions_model->_primary_key = "activities_id";
            $this->transactions_model->save($activities);

            $this->transactions_model->_table_name = "tbl_transactions"; //table name
            $this->transactions_model->_primary_key = "transactions_id";
            $this->transactions_model->delete($id);


            $type = 'success';
        } else {
            $type = 'error';
            $msg = lang('there_in_no_value');
        }
        $message = $msg;
        set_message($type, $message);
        redirect('admin/transactions/expense');
    }

    public function transfer($id = NULL)
    {
        $data['title'] = lang('transfer');
        // get permission user by menu id
        $data['permission_user'] = $this->transactions_model->all_permission_user('32');

        $data['all_transfer_info'] = $this->transactions_model->get_permission('tbl_transfer');
        if (!empty($id)) {
            $transfer_info = $this->transactions_model->check_by(array('transfer_id' => $id), 'tbl_transfer');
            if (!empty($transfer_info))
                $can_edit = $this->transactions_model->can_action('tbl_transfer', 'edit', array('transfer_id' => $id));
            $data['active'] = 2;
            if (!empty($can_edit)) {
                $data['transfer_info'] = $transfer_info;
            }
        } else {
            $data['active'] = 1;
        }
        $data['subview'] = $this->load->view('admin/transactions/transfer', $data, TRUE);
        $this->load->view('admin/_layout_main', $data); //page load
    }

    public function save_transfer($id = NULL)
    {
        if (!empty($id)) {
            $to_account_id = $this->input->post('old_to_account_id', TRUE);
            $from_account_id = $this->input->post('old_from_account_id', TRUE);
            $amount = $this->input->post('old_amount', TRUE);
            $transaction_id = $this->db->select('transactions_id')->where(array('transfer_id' => $id))->get('tbl_transactions')->result();
        } else {
            $to_account_id = $this->input->post('to_account_id', TRUE);
            $from_account_id = $this->input->post('from_account_id', TRUE);
            $amount = $this->input->post('amount', TRUE);
        }
        if (!empty($transaction_id[0]->transactions_id)) {
            $transaction_id_1 = $transaction_id[0]->transactions_id;
        } else {
            $transaction_id_1 = null;
        }
        if (!empty($transaction_id[1]->transactions_id)) {
            $transaction_id_2 = $transaction_id[1]->transactions_id;
        } else {
            $transaction_id_2 = null;
        }

        if ($to_account_id == $from_account_id) {
            $type = 'error';
            $msg = lang('same_account_error');
        } else {
            $from_acc_info = $this->transactions_model->check_by(array('account_id' => $from_account_id), 'tbl_accounts');
            $to_acc_info = $this->transactions_model->check_by(array('account_id' => $to_account_id), 'tbl_accounts');
            if ($amount > $from_acc_info->balance) {
                $type = 'error';
                $msg = lang('amount_exceed_error') . ' <strong style="color:#000"> ' . $from_acc_info->balance . '</strong> !';
            } else {

                $ac_data['balance'] = $from_acc_info->balance - $amount;
                $this->transactions_model->_table_name = "tbl_accounts"; //table name
                $this->transactions_model->_primary_key = "account_id";
                $this->transactions_model->save($ac_data, $from_acc_info->account_id);

                $froma_data['balance'] = $to_acc_info->balance + $amount;
                $this->transactions_model->_table_name = "tbl_accounts"; //table name
                $this->transactions_model->_primary_key = "account_id";
                $this->transactions_model->save($froma_data, $to_acc_info->account_id);


                // save into tbl_transfer
                $transfer_data = $this->transactions_model->array_from_post(array('date', 'notes', 'payment_methods_id', 'reference'));
                $transfer_data['type'] = 'Transfer';
                $transfer_data['to_account_id'] = $to_account_id;
                $transfer_data['from_account_id'] = $from_account_id;
                $transfer_data['amount'] = $amount;

                $fileName = $this->input->post('fileName');
                $path = $this->input->post('path');
                $fullPath = $this->input->post('fullPath');
                $size = $this->input->post('size');
                $is_image = $this->input->post('is_image');

                if (!empty($fileName)) {
                    foreach ($fileName as $key => $name) {
                        $old['fileName'] = $name;
                        $old['path'] = $path[$key];
                        $old['fullPath'] = $fullPath[$key];
                        $old['size'] = $size[$key];
                        $old['is_image'] = $is_image[$key];
                        $result[] = $old;
                    }
                    $transfer_data['attachement'] = json_encode($result);
                }

                if (!empty($_FILES['attachement']['name']['0'])) {
                    $old_path_info = $this->input->post('upload_path');
                    if (!empty($old_path_info)) {
                        foreach ($old_path_info as $old_path) {
                            unlink($old_path);
                        }
                    }
                    $mul_val = $this->transactions_model->multi_uploadAllType('attachement');
                    $transfer_data['attachement'] = json_encode($mul_val);
                }
                if (!empty($result) && !empty($mul_val)) {
                    $file = array_merge($result, $mul_val);
                    $transfer_data['attachement'] = json_encode($file);
                }


                $permission = $this->input->post('permission', true);
                if (!empty($permission)) {
                    if ($permission == 'everyone') {
                        $assigned = 'all';
                    } else {
                        $assigned_to = $this->transactions_model->array_from_post(array('assigned_to'));
                        if (!empty($assigned_to['assigned_to'])) {
                            foreach ($assigned_to['assigned_to'] as $assign_user) {
                                $assigned[$assign_user] = $this->input->post('action_' . $assign_user, true);
                            }
                        }
                    }
                    if ($assigned != 'all') {
                        $assigned = json_encode($assigned);
                    }
                    $transfer_data['permission'] = $assigned;
                } else {
                    set_message('error', lang('assigned_to') . ' Field is required');
                    redirect($_SERVER['HTTP_REFERER']);
                }

                $this->transactions_model->_table_name = "tbl_transfer"; //table name
                $this->transactions_model->_primary_key = "transfer_id";
                $transfer_id = $this->transactions_model->save($transfer_data, $id);

                $from_acc_info = $this->transactions_model->check_by(array('account_id' => $from_account_id), 'tbl_accounts');
                $to_acc_info = $this->transactions_model->check_by(array('account_id' => $to_account_id), 'tbl_accounts');

                // save into tbl_tansactions
                $to_data = $this->transactions_model->array_from_post(array('date', 'notes', 'payment_methods_id', 'reference'));
                $to_data['type'] = 'Transfer';
                $to_data['account_id'] = $to_account_id;
                $to_data['amount'] = $amount;
                $to_data['credit'] = $amount;
                $to_data['total_balance'] = $to_acc_info->balance;
                $to_data['transfer_id'] = $transfer_id;

                $this->transactions_model->_table_name = "tbl_transactions"; //table name
                $this->transactions_model->_primary_key = "transactions_id";
                $this->transactions_model->save($to_data, $transaction_id_1);

                // save into tbl_tansactions
                $from_data = $this->transactions_model->array_from_post(array('date', 'notes', 'payment_methods_id', 'reference'));
                $from_data['type'] = 'Transfer';
                $from_data['account_id'] = $from_account_id;
                $from_data['amount'] = $amount;
                $from_data['debit'] = $amount;
                $from_data['total_balance'] = $from_acc_info->balance;
                $from_data['transfer_id'] = $transfer_id;

                $this->transactions_model->_table_name = "tbl_transactions"; //table name
                $this->transactions_model->_primary_key = "transactions_id";
                $this->transactions_model->save($from_data, $transaction_id_2);

                $type = 'success';
                if (!empty($id)) {
                    $activity = ('activity_update_transfer');
                    $msg = lang('update_a_transfer');
                } else {
                    $activity = ('activity_new_transfer');
                    $msg = lang('save_new_transfer');
                }
                // save into activities
                $activities = array(
                    'user' => $this->session->userdata('user_id'),
                    'module' => 'transactions',
                    'module_field_id' => $id,
                    'activity' => $activity,
                    'icon' => 'fa-coffee',
                    'value1' => $from_acc_info->account_name,
                    'value2' => $to_acc_info->account_name,
                );
                // Update into tbl_project
                $this->transactions_model->_table_name = "tbl_activities"; //table name
                $this->transactions_model->_primary_key = "activities_id";
                $this->transactions_model->save($activities);
            }
        }

        $message = $msg;
        set_message($type, $message);
        redirect($_SERVER['HTTP_REFERER']);
    }

    public function delete_transfer($id)
    {
        $can_delete = $this->transactions_model->can_action('tbl_transfer', 'delete', array('transfer_id' => $id));
        if (!empty($can_delete)) {
            $transfer_info = $this->transactions_model->check_by(array('transfer_id' => $id), 'tbl_transfer');
            $from_acc_info = $this->transactions_model->check_by(array('account_id' => $transfer_info->to_account_id), 'tbl_accounts');
            $to_acc_info = $this->transactions_model->check_by(array('account_id' => $transfer_info->from_account_id), 'tbl_accounts');

            $ac_data['balance'] = $from_acc_info->balance + $transfer_info->amount;
            $this->transactions_model->_table_name = "tbl_accounts"; //table name
            $this->transactions_model->_primary_key = "account_id";
            $this->transactions_model->save($ac_data, $from_acc_info->account_id);

            $froma_data['balance'] = $to_acc_info->balance - $transfer_info->amount;
            $this->transactions_model->_table_name = "tbl_accounts"; //table name
            $this->transactions_model->_primary_key = "account_id";
            $this->transactions_model->save($froma_data, $to_acc_info->account_id);

            $this->transactions_model->_table_name = "tbl_transfer"; //table name
            $this->transactions_model->_primary_key = "transfer_id";
            $this->transactions_model->delete($id);

            $this->transactions_model->_table_name = "tbl_transactions"; //table name
            $this->transactions_model->delete_multiple(array('transfer_id' => $id));

            $activity = ('activity_delete_transfer');
            $msg = lang('delete_transfer');

            // save into activities
            $activities = array(
                'user' => $this->session->userdata('user_id'),
                'module' => 'transactions',
                'module_field_id' => $id,
                'activity' => $activity,
                'icon' => 'fa-coffee',
                'value1' => $from_acc_info->account_name,
                'value2' => $to_acc_info->account_name,
            );
            // Update into tbl_project
            $this->transactions_model->_table_name = "tbl_activities"; //table name
            $this->transactions_model->_primary_key = "activities_id";
            $this->transactions_model->save($activities);

            $type = 'success';
        } else {
            $type = 'error';
            $msg = lang('there_in_no_value');
        }
        $message = $msg;

        set_message($type, $message);
        redirect('admin/transactions/transfer/');
    }

    public function transactions_report($id = null)
    {
        $data['title'] = lang('transactions_report');
        if (!empty($id)) {
            $data['all_transaction_info'] = $this->db->where('account_id', $id)->order_by('transactions_id', 'DESC')->get('tbl_transactions')->result();
        } else {
            $data['all_transaction_info'] = $this->db->order_by('transactions_id', 'DESC')->get('tbl_transactions')->result();
        }
        $data['transactions_report'] = $this->get_transactions_report();
        $data['subview'] = $this->load->view('admin/transactions/transactions_report', $data, TRUE);
        $this->load->view('admin/_layout_main', $data); //page load
    }

    public function get_transactions_report()
    {// this function is to create get monthy recap report
        $m = date('n');
        $year = date('Y');
        $num = cal_days_in_month(CAL_GREGORIAN, $m, $year);
        for ($i = 1; $i <= $num; $i++) {
            if ($m >= 1 && $m <= 9) { // if i<=9 concate with Mysql.becuase on Mysql query fast in two digit like 01.
                $date = $year . "-" . '0' . $m;
            } else {
                $date = $year . "-" . $m;
            }
            $date = $date . '-' . $i;
            $transaction_report[$i] = $this->db->where('date', $date)->order_by('transactions_id', 'DESC')->get('tbl_transactions')->result();
        }
        return $transaction_report; // return the result
    }

    public function transactions_report_pdf()
    {
        $data['title'] = lang('transactions_report');
        $this->load->helper('dompdf');
        $viewfile = $this->load->view('admin/transactions/transactions_report_pdf', $data, TRUE);
        pdf_create($viewfile, lang('transactions_report'));
    }

    public function transfer_report($id = null)
    {
        $data['title'] = lang('transfer_report');
        if (!empty($id)) {
            $check_transfer = $this->db->where('from_account_id', $id)->order_by('transfer_id', 'DESC')->get('tbl_transfer')->result();
            if (!empty($check_transfer)) {
                $data['all_transfer_info'] = $check_transfer;
            } else {
                $data['all_transfer_info'] = $this->db->where('to_account_id', $id)->order_by('transfer_id', 'DESC')->get('tbl_transfer')->result();
            }
        } else {
            $data['all_transfer_info'] = $this->db->order_by('transfer_id', 'DESC')->get('tbl_transfer')->result();
        }
        $data['subview'] = $this->load->view('admin/transactions/transfer_report', $data, TRUE);
        $this->load->view('admin/_layout_main', $data); //page load
    }

    public function transfer_report_pdf()
    {
        $data['title'] = lang('transfer_report');
        $this->load->helper('dompdf');
        $viewfile = $this->load->view('admin/transactions/transfer_report_pdf', $data, TRUE);
        pdf_create($viewfile, lang('transfer_report'));
    }

    public function balance_sheet()
    {
        $data['title'] = lang('balance_sheet');
        $data['subview'] = $this->load->view('admin/transactions/balance_sheet', $data, TRUE);
        $this->load->view('admin/_layout_main', $data); //page load
    }

    public function balance_sheet_pdf()
    {
        $data['title'] = lang('balance_sheet') . ' ' . lang('pdf');
        $this->load->helper('dompdf');
        $viewfile = $this->load->view('admin/transactions/balance_sheet_pdf', $data, TRUE);
        pdf_create($viewfile, lang('balance_sheet'));
    }

    public function download($id)
    {
        $this->load->library('zip');

        $file_info = $this->transactions_model->check_by(array('transactions_id' => $id), 'tbl_transactions');

        $attachement_info = json_decode($file_info->attachement);
        if (!empty($attachement_info)) {
            $total = count($attachement_info);
            foreach ($attachement_info as $attachement) {
                if ($total == 1) {
                    $this->load->helper('download');
                    $down_data = file_get_contents('uploads/' . $attachement->fileName); // Read the file's contents
                    force_download($attachement->fileName, $down_data);
                } else {
                    $multiple = true;
                    $down_data = ('uploads/' . $attachement->fileName); // Read the file's contents
                    $this->zip->read_file($down_data);
                }
            }
            if (!empty($multiple)) {
                $file_name = $file_info->date . ' ' . $file_info->type;
                $this->zip->download($file_name . '.zip');
            }

        } else {
            $type = "error";
            $message = 'Operation Fieled !';
            set_message($type, $message);
            redirect($_SERVER['HTTP_REFERER']);
        }
    }

    public function download_transfer($id)
    {
        $this->load->library('zip');

        $file_info = $this->transactions_model->check_by(array('transfer_id' => $id), 'tbl_transfer');

        $attachement_info = json_decode($file_info->attachement);
        if (!empty($attachement_info)) {
            $total = count($attachement_info);
            foreach ($attachement_info as $attachement) {
                if ($total == 1) {
                    $this->load->helper('download');
                    $down_data = file_get_contents('uploads/' . $attachement->fileName); // Read the file's contents
                    force_download($attachement->fileName, $down_data);
                } else {
                    $multiple = true;
                    $down_data = ('uploads/' . $attachement->fileName); // Read the file's contents
                    $this->zip->read_file($down_data);
                }
            }
            if (!empty($multiple)) {
                $file_name = $file_info->date . ' ' . $file_info->type;
                $this->zip->download($file_name . '.zip');
            }

        } else {
            $type = "error";
            $message = 'Operation Fieled !';
            set_message($type, $message);
            redirect($_SERVER['HTTP_REFERER']);
        }
    }

    public function set_status($action, $id)
    {
        $transaction_info = $this->db->where('transactions_id', $id)->get('tbl_transactions')->row();
        $aaccount_info = $this->transactions_model->check_by(array('account_id' => $transaction_info->account_id), 'tbl_accounts');

        if ($action == 'approved') {
            $status = 'unpaid';
            $activity = 'activity_approved_expense';
        }

        if ($action == 'paid') {
            $status = 'paid';

            $data['amount'] = $transaction_info->amount;
            $data['debit'] = $transaction_info->amount;
            $ac_data['balance'] = $aaccount_info->balance - $data['amount'];

            $this->transactions_model->_table_name = "tbl_accounts"; //table name
            $this->transactions_model->_primary_key = "account_id";
            $this->transactions_model->save($ac_data, $transaction_info->account_id);


            $data['total_balance'] = $aaccount_info->balance;
            $activity = 'activity_paid_expense';
        }
        $data['status'] = $status;
        $this->transactions_model->_table_name = "tbl_transactions"; //table name
        $this->transactions_model->_primary_key = "transactions_id";

        $this->transactions_model->save($data, $id);

        // save into activities
        $activities = array(
            'user' => $this->session->userdata('user_id'),
            'module' => 'transactions',
            'module_field_id' => $id,
            'activity' => $activity,
            'icon' => 'fa-coffee',
            'value1' => $aaccount_info->account_name . ' ' . lang('amount') . $transaction_info->amount,
            'value2' => 'By ' . $this->session->userdata('name'),
        );
        // Update into tbl_project
        $this->transactions_model->_table_name = "tbl_activities"; //table name
        $this->transactions_model->_primary_key = "activities_id";
        $this->transactions_model->save($activities);
        $type = 'success';

        $this->expense_confirmation_email($id, $action);

        $message = lang('update_a_expense');
        set_message($type, $message);
        redirect($_SERVER['HTTP_REFERER']);
    }

    function expense_confirmation_email($id, $action)
    {
        $transaction_info = $this->db->where('transactions_id', $id)->get('tbl_transactions')->row();
        $added_info = $this->db->where('user_id', $transaction_info->added_by)->get('tbl_users')->row();

        // send confirmation to this employee
        if ($action == 'approved') {

            $expense_email = config_item('expense_email');
            if (!empty($expense_email) && $expense_email == 1) {
                $email_template = $this->transactions_model->check_by(array('email_group' => 'expense_approved_email'), 'tbl_email_templates');

                $message = $email_template->template_body;
                $subject = $email_template->subject;
                $username = str_replace("{NAME}", $added_info->username, $message);
                $amount = str_replace("{AMOUNT}", $transaction_info->amount, $username);
                $message = str_replace("{SITE_NAME}", config_item('company_name'), $amount);
                $data['message'] = $message;
                $message = $this->load->view('email_template', $data, TRUE);

                $params['subject'] = $subject;
                $params['message'] = $message;
                $params['resourceed_file'] = '';
                $params['recipient'] = $added_info->email;
                $this->transactions_model->send_email($params);

            }
        }
        if ($action == 'paid') {
            // get departments head user id
            $designation_info = $this->transactions_model->check_by(array('designations_id' => $this->session->userdata('designations_id')), 'tbl_designations');
            // get departments head by departments id
            $dept_head = $this->transactions_model->check_by(array('departments_id' => $designation_info->departments_id), 'tbl_departments');
            $all_admin = $this->db->where('role_id', 1)->get('tbl_users')->result();
            $head = $this->db->where('user_id', $dept_head->department_head_id)->get('tbl_users')->row();

            if (!empty($dept_head->department_head_id) || !empty($all_admin)) {
                $expense_email = config_item('expense_email');
                if (!empty($expense_email) && $expense_email == 1) {
                    $email_template = $this->transactions_model->check_by(array('email_group' => 'expense_paid_email'), 'tbl_email_templates');

                    $message = $email_template->template_body;
                    $subject = $email_template->subject;
                    $username = str_replace("{NAME}", $added_info->username, $message);
                    $amount = str_replace("{AMOUNT}", $transaction_info->amount, $username);
                    $PAID_BY = str_replace("{PAID_BY}", $this->session->userdata('name'), $amount);
                    $Link = str_replace("{URL}", base_url() . 'admin/transactions/expense/view_details/' . $id, $PAID_BY);
                    $message = str_replace("{SITE_NAME}", config_item('company_name'), $Link);
                    $data['message'] = $message;
                    $message = $this->load->view('email_template', $data, TRUE);

                    $params['subject'] = $subject;
                    $params['message'] = $message;
                    $params['resourceed_file'] = '';
                    if (!empty($all_admin)) {
                        foreach ($all_admin as $v_admin) {
                            $params['recipient'] = $v_admin->email;
                            $this->transactions_model->send_email($params);
                            if (!empty($dept_head->department_head_id)) {
                                if ($dept_head->department_head_id == $v_admin->user_id) {
                                    $already_send = 1;
                                }
                            }
                        }
                    }
                    if (empty($already_send)) {
                        $params['recipient'] = $head->email;
                        $this->transactions_model->send_email($params);
                    }

                }
            }
        }
        return true;
    }

}

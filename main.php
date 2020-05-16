<?php

use Moorexa\DB;
use Component\AspNetUser;
use Moorexa\Middleware;
use Moorexa\Middleware as mw;
use Settings as set;
use Component\Http;
use Database\ORM;

/*
 ***************************
 *
 * @quickdoc.requests:(class = Api handler )
 * info: this manages incoming api requests for api.esusu.com
*/

class Api extends ApiManager
{
    //@quickdoc{method: post, action:user(), info:authenticate a customer with username, password or phonenumber or create user account., param:$id (optional), important:action in form field. A switch to determine if it was a login or registration eg <input type="hidden" name="action" value="login"/>}
    public function postUser($action)
    {
        if (!is_null($action))
        {
            //@quickdoc.note: check if action is login or registration
            $action = strtolower($action);

            if ($action == 'auth')
            {
                is_set('username:post|EmailAddress:post', function ($post) use ($action)
                {
                    //@quickdoc.note: verify login fields.
                    $login = AspNetUser::verify(['login', $post]);

                    if ($login->isValid())
                    {
                        $http = new Http('esusu.login2');
                        $http->post(['UserName' => $login->username, 'Password' => $login->password]);
                        $http->send();

                        if (is_object($http->responseJson))
                        {
                            $status = $http
                                ->responseJson->status;

                            if ($status)
                            {
                                $user = DB::AspNetUsers(get('UserName = ? or PhoneNumber = ? and Status = ?')->bind($login->username, $login->username, 1));
                                $requestTime = time();

                                $token = md5(env('bootstrap', 'secret_key')->scalar . $requestTime . $user->UserName);

                                $new = ['userid' => $user->Id];
                                $new['token'] = $token;
                                $new['request_user'] = $user->UserName;
                                $new['request_time'] = $requestTime;
                                $new['cookie'] = Http::$cookieData;

                                $this->http_response('Api-Token', $token);

                                Middleware::apiLimits()->activate($new);

                                $table = ORM::table('aspnetusers');

                                $exists = $table->get('UserName = :un or PhoneNumber = :pn', $user->UserName, $user->PhoneNumber);

                                if ($exists->rowCount() > 0)
                                {
                                    $id = $table->userid;

                                    if (is_int($id))
                                    {
                                        $lastlogin = date('Y-m-d g:i a');

                                        $table->update(['lastlogin' => $lastlogin], 'userid = :id', $id);
                                    }
                                }
                                else
                                {
                                    $table->insert(['UserName' => $user->UserName, 'Password' => encrypt($login->password) , 'lastlogin' => date('Y-m-d g:i a') , 'pinreset' => 0, 'PhoneNumber' => $user->PhoneNumber]);
                                }

                                $user2 = $table->get('UserName = :un or PhoneNumber = :pn', $user->UserName, $user->PhoneNumber);

                                // successfull
                                $this->status('success')
                                    ->id($user->Id)
                                    ->phone($user->PhoneNumber)
                                    ->user($user->UserName)
                                    ->email($user->Email)
                                    ->token($token)->profileimage($table->profile)->ok;
                            }
                            else
                            {
                                $msg = isset($http
                                    ->responseJson
                                    ->msg) ? $http
                                    ->responseJson->msg : "Something went wrong";

                                $this->status('Error')
                                    ->message($msg)->ok;
                            }
                        }
                        else
                        {
                            $this->status('Error')
                                ->message('Authentication Failed. Please try again.')->ok;
                        }
                    }
                    else
                    {
                        $this->status('Error')
                            ->message('Invalid POST Fields')->ok;
                    }
                });
            }
            elseif ($action == 'register')
            {
                $http = new Http('esusu.signup');
                $http->get()
                    ->send();

                $fields = AspNetUser::getInputs($http->response, true);

                $query = implode('&', ['EmailAddress:post', 'Password:post', 'ConfirmPassword:post', 'Lastname:post', 'Othernames:post', 'PhoneNumber:post', 'DateOfBirth:post', 'BVN:post', 'SecurityAnswer:post', 'SecurityQuestion:post']);

                if (!is_set($query))
                {
                    $this->status('Error')
                        ->message('Invalid Registration POST Fields')->ok;
                }
                else
                {
                    is_set($query, function ($data) use ($http)
                    {

                        // check for phone number
                        $phone = DB::Customers(get()->where('PhoneNumber = ?')
                            ->bind($data->PhoneNumber));

                        if ($phone->rows == 0)
                        {
                            $errors = ['email' => false, 'phone' => false];

                            // can continue
                            $canContinue = true;

                            // check email
                            $emailValid = DB::Customers(get()->where('EmailAddress = ?')
                                ->bind($data->EmailAddress));

                            if ($emailValid->rows > 0)
                            {
                                $canContinue = false;
                                $errors['email'] = true;
                            }

                            // check phone number
                            $phoneValid = DB::Customers(get()->where('PhoneNumber = ?')
                                ->bind($data->PhoneNumber));

                            if ($phoneValid->rows > 0)
                            {
                                $canContinue = false;
                                $errors['phone'] = true;
                            }

                            if ($canContinue)
                            {
                                $data->HasAcceptedTermsAndCondition = 'true';

                                $http = new Http('esusu.signup');
                                $http->post($data)->send();

                                $res = $http->response;

                                $error = AspNetUser::getErrors($res);

                                if (is_object($error))
                                {
                                    // get getErrors
                                    $errors = [];

                                    foreach ($error as $err)
                                    {
                                        foreach ($err as $key => $_err)
                                        {
                                            $errors[] = $_err;
                                        }
                                    }

                                    $error->message('There is an error in your form. ' . implode(', ', $errors))->ok;
                                }
                                else
                                {
                                    if ($http->headers['redirect_url'] != "")
                                    {
                                        $this->status('Success')
                                            ->message('Registration successful. You can login to your ewallet with your Email/Phonenumber and password.')->ok;
                                    }
                                    else
                                    {
                                        $this->status('Pending')
                                            ->message('Request waiting, You may have to resubmit later.')->ok;
                                    }
                                }
                            }
                            else
                            {
                                extract($errors);

                                if ($email && $phone)
                                {
                                    $this->status('Error')
                                        ->message('Registration failed. Email and Phone number already in use.')->ok;
                                }
                                elseif ($email)
                                {
                                    $this->status('Error')
                                        ->message('Registration failed. Email address already in use.')->ok;
                                }
                                elseif ($phone)
                                {
                                    $this->status('Error')
                                        ->message('Registration failed. Phone number already in use.')->ok;
                                }
                            }
                        }
                        else
                        {
                            $this->status('Error')
                                ->message('Phone number already in use')->ok;
                        }

                    });
                }
            }
            elseif ($action == 'reset')
            {
                is_set('username:post|EmailAddress:post', function ($post) use ($action)
                {
                    // check if username exists in aspnetusers
                    // check if username exists
                    $exists = DB::AspNetUsers(get('UserName = ? or PhoneNumber = ? and Status = ?')->bind($post->username, $post->username, 1));

                    if ($exists->rows > 0)
                    {
                        $build = ['UserName' => $post->username, 'SecurityAnswer' => $post->securityanswer];

                        $http = new Component\Http('esusu.forget');
                        $http->post($build);
                        $http->send();

                        if (is_object($http->responseJson))
                        {
                            $res = $http->responseJson;

                            $status = $res->success == false ? 'Error' : 'Success';

                            $this->status($status)->message($res->msg)->ok;
                        }
                        else
                        {
                            $this->status('Error')
                                ->message("Operation failed. Please try again")->ok;
                        }
                    }
                    else
                    {
                        $this->status('Failed')
                            ->message("Invalid User Account.")->ok;
                    }
                });

            }
        }
    }

    // check if a user has an acccount with the platform
    public function getCheckUser()
    {
        is_set('username:get', function ($e)
        {

            $user = DB::AspNetUsers(get()->where('UserName = ? or PhoneNumber = ?')
                ->bind(strip_tags($e->username) , strip_tags($e->username)));

            if ($user->rows > 0)
            {
                $user = $user->object;

                // check in our database
                $exists = $this
                    ->mysql
                    ->aspnetusers(get('UserName = ? or UserName = ?')
                    ->bind($e->username, $user->PhoneNumber));

                $message = "";

                if ($exists->rows > 0)
                {
                    $last = new DateTime($exists->lastlogin);
                    $now = new DateTime();

                    $diff = $now->diff($last);
                    $month = $diff->m;
                    $d = $diff->d;
                    $h = $diff->h;
                    $m = $diff->i;

                    $message = 'You have been away for ';

                    if ($month > 0)
                    {
                        $s = $month > 1 ? 's' : '';

                        $message .= $month . ' month' . $s . ', ';
                    }

                    if ($d > 0)
                    {
                        $s = $d > 1 ? 's' : '';

                        $message .= $d . ' day' . $s . ', ';
                    }

                    if ($h > 0)
                    {
                        $s = $h > 1 ? 's' : '';
                        $s2 = $m > 1 ? 's' : '';

                        $message .= $h . ' hour' . $s . ', ' . $m . ' minute' . $s2 . ', ';
                    }
                    else
                    {
                        if ($m > 0)
                        {
                            $s2 = $m > 1 ? 's' : '';

                            $message .= $m . ' minute' . $s2 . ', ';
                        }
                        else
                        {
                            $message = 'You should take plenty of water and exercise daily. ';
                        }

                    }

                    $message .= 'we hope you are doing great! We miss you.';

                    $this->status('Success')
                        ->message($message)->header('Welcome Back ' . $user->Lastname)->ok;
                }
                else
                {
                    $this->status('Success')
                        ->message("Howdy! How are you today? We hope you are doing great! Your friends from esusuonline.")
                        ->header('Welcome ' . $user->Lastname)->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('Invalid User. Please check your login and try again')->ok;
            }
        });

        if (!is_set('username:get'))
        {
            $this->status('Error')
                ->message('Request parameter not set.')->ok;
        }
    }

    // created for the mobile app
    public function postDevice($meth)
    {
        if ($meth == 'check')
        {
            is_set('username:post', function ($e)
            {

                $user = DB::AspNetUsers(get()->where('UserName = ? or PhoneNumber = ?')
                    ->bind(strip_tags($e->username) , strip_tags($e->username)));

                if ($user->rows > 0)
                {
                    $uname = strip_tags($e->username);

                    $table = ORM::table('aspnetusers');

                    $uid = $table->get('UserName = :un or PhoneNumber = :pn', $uname, $uname);

                    if ($uid->rowCount() > 0)
                    {
                        $obj = $table;

                        $id = $obj->userid;

                        // activate
                        // check if exists
                        $table = ORM::table('devices');

                        $device = $table->get('userid = :uid', $id);

                        if ($device->rowCount() > 0)
                        {
                            $device = $table;

                            if ($table->deviceHash == $e->deviceHash)
                            {
                                $this->status('Success')
                                    ->message('Activated')->ok;
                            }
                            else
                            {
                                $this->status('Error')
                                    ->message('Not activated')->ok;
                            }
                        }
                        else
                        {
                            $this->status('Error')
                                ->message('Not activated')->ok;
                        }
                    }
                    else
                    {
                        $this->status('Error')
                            ->message('Not activated')->ok;
                    }
                }
                else
                {
                    $this->status('Error')
                        ->message('Invalid User Account. Please check and try again.')->ok;
                }

            });

            $this->status('Error')
                ->message('Not activated')->ok;

        }
        elseif ($meth == 'activate')
        {
            is_set('username:post', function ($e)
            {

                $user = DB::AspNetUsers(get()->where('UserName = ? or PhoneNumber = ?')
                    ->bind(strip_tags($e->username) , strip_tags($e->username)));

                if ($user->rows > 0)
                {
                    $uname = strip_tags($e->username);

                    $table = ORM::table('aspnetusers');

                    $uid = $table->get('UserName = :un or PhoneNumber = :pn', $uname, $uname);

                    if ($uid->rowCount() == 0)
                    {
                        // insert user
                        $user = $user->object;

                        $insert = [];
                        $insert['UserName'] = $user->Email;
                        $insert['PhoneNumber'] = $user->PhoneNumber;
                        $insert['lastlogin'] = date('Y-m-d g:i:s a');
                        $insert['Password'] = md5($user->Email);
                        $insert['pinreset'] = 0;

                        $table->insert($insert);

                        $uname = strip_tags($e->username);

                        $uid = $table->get('UserName = :un or PhoneNumber = :pn', $uname, $uname);

                    }

                    if ($uid->rowCount() > 0)
                    {
                        $obj = $table;

                        $id = $obj->userid;

                        $hash = encrypt($e->username . $id . time() . $_SERVER['HTTP_USER_AGENT']);

                        $devices = ORM::table('devices');

                        $insert = [];
                        $insert['userid'] = $id;
                        $insert['deviceHash'] = sha1($hash);

                        // check if exists
                        $device = $devices->get('userid = :id', $id);

                        $user = DB::AspNetUsers(get()->where('UserName = ? or PhoneNumber = ?')
                            ->bind(strip_tags($e->username) , strip_tags($e->username)));

                        if ($user->rows > 0)
                        {
                            $user = $user->object;
                            $phone = $user->PhoneNumber;
                            $email = $user->Email;

                            //$code = mt_rand(1000,5000);
                            $code = '0000';

                            ORM::table('aspnetusers')->update(['activationCode' => $code], 'userid = :id', $id);

                            $send = Plugins::sms()->send('EsusuOnline', 'Enter ' . $code . ' to activate your device.', $phone);

                            Plugins::mail()->send($email, 'Activation Code', 'Your Activation code is ' . $code);

                            $time = date('Y-m-d g:i:s a', strtotime('+15 minutes'));

                            $method = 'insert';

                            if ($device->rowCount() > 0)
                            {
                                $method = 'update';
                            }

                            $build = ['data' => $insert, 'time' => $time, 'code' => $code, 'method' => $method, 'email' => $email, 'phone' => $phone];

                            $this->status('Success')
                                ->build($build)->ok;
                        }
                        else
                        {
                            $this->status('Error')
                                ->message('Invalid User Account. Please check and try again.')->ok;
                        }

                    }

                }
                else
                {
                    $this->status('Error')
                        ->message('Invalid User Account. Please check and try again.')->ok;
                }
            });

            is_set('userid:post', function ($e)
            {

                $code = $e->code;
                $id = $e->userid;
                $deviceHash = $e->deviceHash;
                $method = $e->method;

                $time = new DateTime($e->time);

                $current = new DateTime();
                $diff = $current->diff($time);

                if ($diff->i < 15)
                {
                    $build = [];
                    $build['deviceHash'] = $deviceHash;

                    $table = ORM::table('devices');

                    if ($method == 'update')
                    {
                        $table->update($build, 'userid = :id', $id);
                    }
                    else
                    {
                        $build['userid'] = $id;
                        $table->insert($build);

                    }

                    $this->status('Success')
                        ->message('Device Registered.')->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message('Code expired. Sorry, but you have to make a new request.')->ok;
                }
            });
        }
        elseif ($meth == 'login')
        {
            is_set('username:post', function ($e)
            {
                $time = ['lastlogin' => date('Y-m-d g:i a') ];

                $this
                    ->mysql
                    ->aspnetusers(update($time)->where('UserName = :uname or PhoneNumber = :phone')
                    ->bind($e->username, $e->username));

                $this->status('Success')
                    ->message('logged in')->ok;
            });
        }
    }

    //@quickdoc{method: get, action:user(), info: get a customer information, param:$id(int) optional}
    public function getUser($id)
    {
        if (is_set('userid:get'))
        {
            $userid = $_GET['userid'];
            $customer = DB::Customers(get()->where('UserProfileID = ?')
                ->bind($userid));
            if ($customer->rows > 0)
            {
                $id = $customer
                    ->object->ID;
            }
            $customer = null;
        }

        if (is_null($id))
        {
            $user = DB::AspNetUsers(get()->where('UserName = ?')
                ->bind(session('api.request.user')));

            $id = $user->Id;

            // get customer info
            $customer = DB::Customers(get()->where('UserProfileID = ?')
                ->bind($id));

            if ($user->Designation == 'superadmin')
            {
                $user1 = toArray($user);
                $cus = toArray($customer);
            }
            else
            {
                $user1 = ['Email' => $user->Email, 'PhoneNumber' => $user->PhoneNumber, 'UserName' => $user->UserName, 'lastName' => $user->lastName, 'Othernames' => $user->Othernames, 'RegistrationDate' => $user->RegistrationDate, ];

                $cus = ['BVN' => $customer->BVN, 'EmailConfirmed' => $customer->IsEmailConfirmed, 'ProfileComplete' => $customer->IsProfileComplete, 'PhoneNumberConfirmed' => $customer->IsPhoneNumberConfirmed, 'KYCComplete' => $customer->IsKYCComplete, 'DateOfBirth' => $customer->DateOfBirth, 'MaritalStatus' => $customer->MaritalStatus, 'Occupation' => $customer->Occupation, 'Religion' => $customer->Religion, 'Nationality' => $customer->Nationality, 'NextOfKinName' => $customer->NextOfKinName, 'NextOfKinPhoneNumber' => $customer->NextOfKinPhoneNumber, 'NextOfKinOccupation' => $customer->NextOfKinOccupation, 'NextOfKinAddress' => $customer->NextOfKinAddress, 'ResidentialAddress' => $customer->ResidentialAddress, 'CustomerID' => $customer->ID];
            }

            $data = [];
            $data = array_merge($user1, $cus);

            if (count($data) > 0)
            {
                $this->status('Success')
                    ->data($data)->ok;
            }
            else
            {
                $this->status('Error')
                    ->message('Request Failed. Please try again.')->ok;
            }
        }
        else
        {
            // get customer info
            $customer = DB::Customers(get()->where('ID = ?')
                ->bind($id));

            if ($customer->rows > 0)
            {
                $user = DB::AspNetUsers(get()->where('UserProfileID = ?')
                    ->bind($customer->UserProfileID));

                if (isset($user->Designation) && $user->Designation == 'superadmin')
                {
                    $user1 = toArray($user);
                    $cus = toArray($customer);
                }
                else
                {
                    $user1 = [];

                    if (!is_null($user) && isset($user->rows) && $user->rows > 0)
                    {
                        $user1 = ['Email' => $user->Email, 'PhoneNumber' => $user->PhoneNumber, 'UserName' => $user->UserName, 'lastName' => $user->lastName, 'Othernames' => $user->Othernames, 'RegistrationDate' => $user->RegistrationDate, 'UserProfileID' => $user->UserProfileID];
                    }

                    $cus = ['BVN' => $customer->BVN, 'EmailConfirmed' => $customer->IsEmailConfirmed, 'ProfileComplete' => $customer->IsProfileComplete, 'PhoneNumberConfirmed' => $customer->IsPhoneNumberConfirmed, 'KYCComplete' => $customer->IsKYCComplete, 'DateOfBirth' => $customer->DateOfBirth, 'MaritalStatus' => $customer->MaritalStatus, 'Occupation' => $customer->Occupation, 'Religion' => $customer->Religion, 'Nationality' => $customer->Nationality, 'NextOfKinName' => $customer->NextOfKinName, 'NextOfKinPhoneNumber' => $customer->NextOfKinPhoneNumber, 'NextOfKinOccupation' => $customer->NextOfKinOccupation, 'NextOfKinAddress' => $customer->NextOfKinAddress, 'ResidentialAddress' => $customer->ResidentialAddress, 'CustomerID' => $id];
                }

                $data = [];
                $data = array_merge($user1, $cus);

                if (count($data) > 0)
                {
                    $this->status('Success')
                        ->data($data)->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message('Request Failed. Please try again.')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('Invalid CustomerID')->ok;
            }
        }
    }

    //@quickdoc{method: post, action:agent(), info: submit agent request, param:$action{request-type} $cid(int){CustomerID} optional}
    public function putAgent(string $action, $cid = null)
    {
        $info = mw::customer()->info($cid);

        if ($action == 'request')
        {
            if (isset($info
                ->data
                ->ID))
            {
                $cid = $info
                    ->data->ID;

                // check if already an agent
                $isRequested = DB::AgentRequests(get()->where('CustomerID = ?')
                    ->bind($cid));

                if ($isRequested->rows == 0)
                {
                    // check Agent lists
                    $Customer = DB::Customers(get()->where('ID = ?')
                        ->bind($cid));

                    $info = mw::customer()->info($cid);

                    if ($info
                        ->data->IsKYCComplete == 1)
                    {

                        if (!is_null($Customer) && $Customer->rows > 0)
                        {
                            $profileID = $Customer->UserProfileID;
                            $isAgent = DB::Agents(get()->where('UserProfileID = ?')
                                ->bind($profileID));

                            if (!is_null($isAgent) && $isAgent->rows == 0)
                            {
                                $date = date('Y-m-d g:i:s');
                                // not an agent, can submit request now.
                                $insert = DB::AgentRequests(insert(['CustomerID' => $cid, 'Status' => 1, 'DateRequested' => $date]));

                                if ($insert !== false)
                                {
                                    if ($insert->ok)
                                    {
                                        $this->status('Success')
                                            ->message('Your request to be an agent has been submitted.')->ok;
                                    }
                                }
                                else
                                {
                                    $this->status('Error')
                                        ->message('Unable to make request at this time, please try again later.')->ok;
                                }
                            }
                            else
                            {
                                // already an agent
                                $this->status('Error')
                                    ->message('Already an Esusu Agent. Cannot make a request again.')->ok;
                            }
                        }
                        else
                        {
                            // not a valid user
                            $this->status('Error')
                                ->message('Account Verification failed.')->ok;
                        }
                    }
                    else
                    {
                        $this->status('Error')
                            ->message('KYC not Complete. Cannot make request.')->ok;
                    }
                }
                else
                {
                    // made a request previously
                    $this->status('Error')
                        ->message('Already made a request to be an agent.')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message("Invalid Esusu Customer Account!")->ok;
            }
        }
        elseif ($action == 'liquidate')
        {
            // check agent request
            if (isset($info
                ->data
                ->ID))
            {
                is_set('SerialNumber:post&VoucherCode:post&CustomersPhoneNo:post&CustomersPIN:post', function ($data) use ($info)
                {

                    $http = new Http('esusu.agent.liquidate');
                    $http->post($data)->send();

                    if (is_object($http->responseJson))
                    {
                        if (isset($http
                            ->responseJson
                            ->status))
                        {
                            $status = $http
                                ->responseJson->status == true ? 'Success' : 'Error';
                        }
                        else
                        {
                            $status = 'Error';
                        }

                        $this->status($status)->message($http
                            ->responseJson
                            ->msg)->ok;
                    }
                    else
                    {
                        $this->status('Error')
                            ->message('Voucher Liquidation failed!')->ok;
                    }
                });

                if (!is_set('SerialNumber:post&VoucherCode:post&CustomersPhoneNo:post'))
                {
                    $this->status('Error')
                        ->message('Invalid POST Fields')->ok;
                }
            }
            else
            {
                AspNetUser::authenticate();
            }
        }
        else
        {
            $this->status('Error')
                ->message('Invalid Api request ' . $action)->ok;
        }
    }

    //@quickdoc{method: get, action:agent(), info: get an agent status, information, param:$action{request-type} $cid(int){CustomerID} optional}
    public function getAgent($action, $cid = null)
    {
        if ($action == 'status')
        {
            if (is_null($cid))
            {
                if (session('api.userid'))
                {
                    $id = decrypt(session('api.userid'));

                    // revalidate user
                    $check = DB::AspNetUsers(get()->where("ID = ?")
                        ->bind($id));

                    if ($check->rows > 0)
                    {
                        // get customer id
                        $customer = DB::Customers(get()->where('UserProfileID = ?')
                            ->bind($id));

                        if ($customer->rows > 0)
                        {
                            $CustomerID = $customer->ID;

                            //  now get details
                            $this->getAgent('status', $CustomerID);
                        }
                        else
                        {
                            $this->status('Error')
                                ->message('Customer account not found. Cannot make request')->ok;
                        }
                    }
                    else
                    {
                        $this->status('Error')
                            ->message('Invalid Esusu User account.')->ok;
                    }
                }
                else
                {
                    $this->status('Error')
                        ->message('User not authenticated.')->ok;
                }
            }
            else
            {
                // get agent information
                $agent = DB::AgentRequests(get()->where('CustomerID = ?')
                    ->bind((int)$cid));

                if ($agent->rows > 0)
                {
                    $this->status($agent->Status)
                        ->dateRequested($agent->DateRequested)
                        ->dateUpdated($agent->DateUpdated)->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message('Not an Esusu Agent, please make a request.')->ok;
                }
            }
        }
        elseif ($action == 'report')
        {
            if (is_set('type:get'))
            {
                $type = $_GET['type'];

                if ($type == 'view-vouchers')
                {
                    $http = new Http('http://esusuonline.com/ewalletAdmin/Voucher/AgentIndex');
                    $http->get()
                        ->send();

                    var_dump($http->response);
                }
            }
        }
        elseif (is_numeric($action))
        {
            // get customer UserProfileID
            $cus = DB::Customers(get()->where('ID = ?')
                ->bind((int)$action));

            if ($cus->rows > 0)
            {
                $UserProfileID = $cus->UserProfileID;

                // check customer in dbo.agents
                $agent = DB::Agents(get()->where('UserProfileID = ?')
                    ->bind($UserProfileID));

                if ($agent->rows > 0)
                {
                    $this->status('Success')
                        ->data($agent->object)->ok;
                }
                else
                {
                    // not an agent
                    $this->status('Error')
                        ->message('Customer not an Esusu Agent.')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('Invalid Customer ID.')->ok;
            }
        }
        else
        {
            if (session('api.userid'))
            {
                $id = decrypt(session('api.userid'));

                // revalidate user
                $check = DB::AspNetUsers(get()->where("ID = ?")
                    ->bind($id));

                if ($check->rows > 0)
                {
                    // get customer id
                    $customer = DB::Customers(get()->where('UserProfileID = ?')
                        ->bind($id));

                    if ($customer->rows > 0)
                    {
                        $CustomerID = $customer->ID;

                        //  now get details
                        $this->getAgent($CustomerID);
                    }
                    else
                    {
                        $this->status('Error')
                            ->message('Customer account not found. Cannot make request')->ok;
                    }
                }
                else
                {
                    $this->status('Error')
                        ->message('Invalid Esusu User account.')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('User not authenticated.')->ok;
            }
        }
    }

    // return all account information in one request.
    // created for the mobile app.
    public function getAccountInfo($cid)
    {
        // get customer info
        ob_start();
        $this->getCustomer('view', $cid);
        $customer = json_decode(ob_get_contents());
        ob_clean();

        // get bank info
        ApiManager::$json_sent = false;
        ob_start();
        $this->json = [];
        $this->getBank($cid);
        $bank = json_decode(ob_get_contents());
        ob_clean();

        // get all reports
        ApiManager::$json_sent = false;
        $this->json = [];
        ob_start();
        $this->getReport('all', $cid);
        $reports = json_decode(ob_get_contents());
        ob_clean();

        // get savings
        ApiManager::$json_sent = false;
        $this->json = [];
        ob_start();
        $this->getCustomer('plan', $cid);
        $savings = json_decode(ob_get_contents());
        ob_clean();

        // get balance
        ApiManager::$json_sent = false;
        $this->json = [];
        ob_start();
        $this->getCustomer('balance', $cid);
        $balance = json_decode(ob_get_contents());
        ob_clean();

        // get all plans
        ApiManager::$json_sent = false;
        $this->json = [];
        ob_start();
        $this->getSaving('plans', $cid);
        $allplans = json_decode(ob_get_contents());
        ob_clean();

        // get all savings report
        ApiManager::$json_sent = false;
        $this->json = [];
        ob_start();
        $this->getSaving('report', $cid, 'all');
        $allplansreport = json_decode(ob_get_contents());
        ob_clean();

        // get all savings interest
        ApiManager::$json_sent = false;
        $this->json = [];
        ob_start();
        $this->getSaving('interest', $cid);
        $interest = json_decode(ob_get_contents());
        ob_clean();

        $plan = isset($savings
            ->data
            ->plan) ? $savings
            ->data->plan : null;

        echo json_encode(['plans' => $plan, 'allreport' => $reports->report??null, 'bank' => $bank, 'profile' => $customer, 'balance' => $balance->balance, 'accountNumber' => $balance->accountNumber, 'allplans' => $allplans, 'allplansreport' => $allplansreport, 'interest' => $interest]);
    }

    //@quickdoc{method: get, action:customer(), info:view customer information, param:$action{request-type} $cid(int){CustomerID} optional}
    public function getCustomer($action = 'view', $cid = null)
    {
        if ($action == 'view')
        {
            if (!is_null($cid))
            {
                $info = mw::customer()->info($cid);

                $info
                    ->data->DateCreated = date('Y-m-d g:i a', strtotime($info
                    ->data
                    ->DateCreated
                    ->date));

                if ($info->ok)
                {
                    $this->status('Success')
                        ->data($info->data)->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message($info->message)->ok;
                }
            }
            else
            {
                $info = mw::customer()->info();

                $info
                    ->data->DateCreated = date('Y-m-d g:i a', strtotime($info
                    ->data
                    ->DateCreated
                    ->date));

                if ($info->ok)
                {
                    $this->status('Success')
                        ->data($info->data)->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message($info->message)->ok;
                }
            }
        }
        elseif ($action == 'balance')
        {

            $info = mw::customer()->info($cid);

            $ID = $info
                ->data->ID;

            $Account = DB::CustomerMobileAccounts(get()->where('CustomerID = ?')
                ->bind($ID));

            if ($Account->row > 0)
            {
                $this->status('Success')
                    ->balance($this->convertToNaria($Account->Balance))
                    ->bvn($info
                    ->data
                    ->BVN)
                    ->accountNumber($Account->AccountNumber)->ok;
            }
            else
            {
                $this->status('Error')
                    ->message('Invalid Customer Account.')->ok;
            }

        }
        elseif ($action == 'plan')
        {
            $info = mw::customer()->info($cid);

            $ID = $info
                ->data->ID;

            $check = DB::CustomerProducts(get()->where('CustomerID = ?')
                ->bind($ID));

            $activePlans = [];
            $allPlans = [];

            if ($check->row > 0)
            {
                // get Products
                $data = [];
                $index = 0;

                while ($c = $check->object())
                {
                    $ProductID = $c->ProductID;

                    $product = DB::Products(get()->where('ID = ?')
                        ->bind($ProductID));

                    if ($product->row > 0)
                    {
                        $created = new DateTime($c
                            ->DateCreated
                            ->date);
                        $due = new DateTime($c
                            ->DueDate
                            ->date);

                        $dueTimeStamp = $due->getTimeStamp();
                        $current = new DateTime();
                        $currentTimestamp = $current->getTimeStamp();

                        $interest = DB::Interests(get()->where('ID = ?')
                            ->bind($product->InterestID));

                        $acc = $this->getAccuredInterest($c->ID);

                        $data[$index][$product->Name] = [];
                        $data[$index][$product->Name]['PercentageOfInterest'] = $product->PercentageOfInterest;
                        $data[$index][$product->Name]['NumberOfSavingsPerMonth'] = $product->NumberOfSavingsPerMonth;
                        $data[$index][$product->Name]['NumberOfWithdrawalsPerMonth'] = $product->NumberOfWithdrawalsPerMonth;
                        $data[$index][$product->Name]['Description'] = $product->Description;
                        $data[$index][$product->Name]['Duration'] = $product->Duration;
                        $data[$index][$product->Name]['ExcessWithdrawalCharge'] = $product->ExcessWithdrawalCharge;
                        $data[$index][$product->Name]['TransferToWalletOnMaturity'] = $product->TransferToWalletOnMaturity;
                        $data[$index][$product->Name]['MinimumBalance'] = $this->convertToNaria($product->MinimumBalance);
                        $data[$index][$product->Name]['MaximumBalance'] = $this->convertToNaria($product->MaximumBalance);
                        $data[$index][$product->Name]['AvailableBalance'] = $this->convertToNaria($c->AvailableBalance);
                        $data[$index][$product->Name]['LedgerBalance'] = $this->convertToNaria($c->LedgerBalance);
                        $data[$index][$product->Name]['StandingOrder'] = $this->convertToNaria($c->Amount);
                        $data[$index][$product->Name]['DueDate'] = $due->format('Y-m-d g:i a');
                        $data[$index][$product->Name]['DateCreated'] = $created->format('Y-m-d g:i a');
                        $data[$index][$product->Name]['ID'] = $c->ID;
                        $data[$index][$product->Name]['ProductID'] = $c->ProductID;
                        $data[$index][$product->Name]['CustomerID'] = $c->CustomerID;
                        $data[$index][$product->Name]['Status'] = $c->Status != 3 ? 'Active' : 'Closed';
                        $data[$index][$product->Name]['Code'] = $c->Code;
                        $data[$index][$product->Name]['Interest'] = $interest->Rate;
                        $data[$index][$product->Name]['AccuredInterest'] = $acc;
                        $data[$index][$product->Name]['SavingsFrequency'] = $product->SavingsFrequency == 1 ? 'Daily' : 'Monthly';

                        if ($dueTimeStamp > $currentTimestamp)
                        {
                            $activePlans[] = $data[$index];
                        }
                        else
                        {
                            $allPlans[] = $data[$index];
                        }

                        $index++;
                    }
                }

                $this->status('Success')
                    ->data(['plan' => array_merge($activePlans, $allPlans) ])->ok;

            }
            else
            {
                $this->status('Error')
                    ->message('Customer do not have an active plan.')->ok;
            }
        }
        elseif ($action == 'interest')
        {
            $mobileAccount = DB::CustomerMobileAccounts(get()->where('CustomerID = ?')
                ->bind($cid));

            if ($mobileAccount->row > 0)
            {
                $MID = $mobileAccount->ID;

                $intrest = DB::CustomerProductInterests(get()->where('CustomerMobileAccountID = ?')
                    ->bind($MID));

                if ($intrest->rows > 0)
                {
                    $data = [];

                    while ($in = $intrest->object())
                    {
                        if ($in->CustomerProductID != 0)
                        {
                            $product = DB::Products(get()->where('ID = ?')
                                ->bind($in->CustomerProductID));

                            $data[$product->Name] = [];
                            $data[$product->Name]['duration'] = $product->Duration;
                            $data[$product->Name]['amount'] = $this->convertToNaria($in->Amount);

                            $intr = DB::Interests(get()->where('ID = ?')
                                ->bind($product->InterestID));

                            $data[$product->Name]['intrest_type'] = $intr->Name;
                            $data[$product->Name]['rate'] = $intr->Rate;
                        }
                    }

                    if (count($data) > 0)
                    {
                        $this->status('Success')
                            ->data($data)->ok;
                    }
                    else
                    {
                        $this->status('Error')
                            ->message('No Active Product Interest')->ok;
                    }
                }
                else
                {
                    $this->status('Error')
                        ->message('No Product Interest.')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('Customer doesn\'t have a MobileAccount.')->ok;
            }

        }
        elseif ($action == 'bank')
        {
            $info = mw::customer()->info($cid);

            $ID = $info
                ->data->ID;

            $account = DB::CustomerBankAccounts(get()->where('CustomerID = ?')
                ->bind($ID));

            if ($account->rows > 0)
            {
                $bankid = $account->BankID;

                // get bank
                $bank = DB::Banks(get()->where('ID = ?')
                    ->bind($bankid));

                $this->status('success')
                    ->bank($bank->BankName)
                    ->accountName($account->AccountHolderName)
                    ->accountNumber($account->AccountNumber)->ok;
            }
            else
            {
                $this->status('error')
                    ->message('Invalid Customer account.')->ok;
            }
        }
        elseif ($action == 'withdrawal')
        {
            $limit = 20;
            if (isset($_GET['limit']))
            {
                $lm = filter_var($_GET['limit'], FILTER_VALIDATE_INT);
                if (is_integer($lm))
                {
                    $limit = $lm;
                }
            }

            $info = mw::customer()->info($cid);

            $account = DB::CustomerBankAccounts(get()->where('CustomerID = ?')
                ->bind($info
                ->data
                ->ID));
            $mobileAccount = DB::CustomerMobileAccounts(get()->where('CustomerID = ?')
                ->bind($info
                ->data
                ->ID));

            $record = [];
            $data = [];

            if ($account->rows > 0)
            {
                $AccountNumber = $account->AccountNumber;

                if ($mobileAccount->rows > 0)
                {
                    $wallet = DB::sql("SELECT * FROM RealTimePostings WHERE AccountNumber = ? OR AccountNumber = ? ORDER BY ID DESC OFFSET 0 ROWS FETCH NEXT $limit ROWS ONLY")->bind($AccountNumber, $mobileAccount->AccountNumber)
                        ->run();
                }
                else
                {
                    $wallet = DB::sql("SELECT * FROM RealTimePostings WHERE AccountNumber = ? ORDER BY ID DESC OFFSET 0 ROWS FETCH NEXT $limit ROWS ONLY")->bind($AccountNumber)->run();
                }

                if ($wallet->rows > 0 && $wallet->rows !== true)
                {
                    while ($re = $wallet->object())
                    {
                        $naration = $re->Narration;

                        if (preg_match('/(debit|withdraw)/i', $naration))
                        {
                            // get account name
                            $re->AccountHolderName = $account->AccountHolderName;
                            $re->Amount = $this->convertToNaria($re->Amount);
                            $re->RecordType = 'DR';

                            $re->PostingDate = date('Y-m-d g:i', strtotime($re
                                ->PostingDate
                                ->date));
                            $record[] = $re;
                        }
                    }
                }
            }

            $this->status('Success')
                ->report($record)->ok;
        }
        else
        {
            $this->status('Error')
                ->message('Invalid Api request ' . $action)->ok;
        }
    }

    // agent transfer
    public function postTransfer($cid = null)
    {
        // get customer information
        $info = mw::customer()->info($cid);
        if ($info->ok)
        {
            // cool
            $http = new Http('esusu.bank.transfer');
            is_set('RecepientAccountNumber:post&DepositAmount:post&BankName:post&PIN:post', function ($post) use (&$http, &$info)
            {

                $post->CustomerID = $info
                    ->data->ID;
                // send
                $http->post($post)->send();

                if (is_object($http->responseJson))
                {
                    $msg = $http
                        ->responseJson->msg;
                    $status = $http
                        ->responseJson->status;

                    $status = $status == true ? 'Success' : 'Error';

                    $this->status($status)->message($msg)->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message("Operation failed. Please try again")->ok;
                }

            });
        }
        else
        {
            $this->status('Error')
                ->message('Authentication required!')->ok;
        }
    }

    private function query($sql)
    {
        $args = func_get_args();
        unset($args[0]);

        Moorexa\DatabaseHandler::$newConnection = true;
        $db = Moorexa\DatabaseHandler::active('mysql');

        $check = $db->prepare($sql);

        if (count($args) > 0)
        {
            $id = 1;

            foreach ($args as $i => $x)
            {
                if (preg_match('/[=]\s{0,}[?]/', $sql))
                {
                    $type = is_bool($x) ? PDO::PARAM_BOOL : is_string($x) ? PDO::PARAM_STR : is_int($x) ? PDO::PARAM_INT : '';

                    if ($type != "")
                    {
                        $check->bindValue($i, $x, $type);
                    }
                }

                $id++;
            }
        }

        $check->execute();

        $data = ['rows' => $check->rowCount() , 'object' => [], 'array' => []];

        if (preg_match('/(select)/i', $sql))
        {
            $object = [];
            $objref = $check;

            if ($objref->rowCount() > 0)
            {
                while ($r = $objref->fetchAll(PDO::FETCH_ASSOC))
                {
                    $object[] = (object)$r;
                }

                if (isset($object[0]))
                {
                    foreach ($object[0] as $index => $val)
                    {
                        $obj_resp = [];

                        foreach ($val as $key => $v)
                        {
                            $obj_resp[$key] = html_entity_decode($v);
                        }

                        // object
                        $data['object'][] = (object)$obj_resp;

                        // array
                        $data['array'][] = (array)$obj_resp;
                    }
                }
            }

        }

        return Moorexa\promise_callback($data);
    }

    // verify PIN
    public function postVerifyPin()
    {
        $http = new Http('esusu.verifypin');

        is_set('PIN:post', function ($post) use ($http)
        {
            $http->post($post)->send();

            if (is_object($http->responseJson))
            {
                $msg = $http
                    ->responseJson->msg;

                $status = $msg == 'Pin Verified' ? 'Success' : 'Error';

                $this->status($status)->message($http
                    ->responseJson
                    ->msg)->ok;
            }
            else
            {
                $this->status('Error')
                    ->message("Operation failed. Please try again")->ok;
            }
        });
    }

    //@quickdoc{method: post, action:customer(), info:update customer information, param:$action{request-type} $cid(int){CustomerID} optional}
    public function postCustomer($action = 'edit', $cid = null)
    {
        if ($action == 'edit')
        {
            $info = mw::customer()->info($cid);

            $data = AspNetUser::filter_post();

            if (count($data) > 0)
            {

                if ($info->ok)
                {
                    $cid = $info
                        ->data->ID;

                    $fields = AspNetUser::customerAllowedFields($cid);

                    if ($fields->ok)
                    {
                        if ($fields->fields === "*")
                        {
                            // allow all
                            $update = DB::Customers(update($data)->where('ID = ?')
                                ->bind($cid));

                            if ($update->ok)
                            {
                                $this->status('Success')
                                    ->message('Edit successfull.')->ok;
                            }
                            else
                            {
                                $this->status('Error')
                                    ->message('Edit failed. Please try again later, server may be busy')->ok;
                            }
                        }
                        else
                        {
                            $allow = $fields->fields;

                            $cols = [];
                            $failed = [];

                            foreach ($data as $key => $val)
                            {
                                if (in_array($key, $allow))
                                {
                                    $cols[$key] = $val;
                                }
                                else
                                {
                                    $failed[] = $key;
                                }
                            }

                            if (count($failed) > 0 && count($cols) == 0)
                            {
                                // all failed.
                                $this->status('Error')
                                    ->message('Failed updating column[s] {' . implode('|', $failed) . '}')->reason('Not Allowed')->ok;
                            }
                            elseif (count($failed) > 0 && count($cols) > 0)
                            {
                                // update method here..
                                $update = DB::Customers(update($cols)->where('ID = ?')
                                    ->bind($cid));

                                if ($update->ok)
                                {
                                    $this->status('Success')
                                        ->message('Edit successfull.')
                                        ->message2('Failed updating column[s] {' . implode('|', $failed) . '}')->reason('Not Allowed')->ok;
                                }
                                else
                                {
                                    $this->status('Error')
                                        ->message('Edit failed. Please try again later, server may be busy')->ok;
                                }

                            }
                            elseif (count($cols) > 0 && count($failed) == 0)
                            {
                                // update method here..
                                $update = DB::Customers(update($cols)->where('ID = ?')
                                    ->bind($cid));

                                if ($update->ok)
                                {
                                    $this->status('Success')
                                        ->message('Edit successfull.')->ok;
                                }
                                else
                                {
                                    $this->status('Error')
                                        ->message('Edit failed. Please try again later, server may be busy')->ok;
                                }

                            }
                        }
                    }
                    else
                    {
                        $this->status('Error')
                            ->message($fields->message)->ok;
                    }
                }
                else
                {
                    $this->status('Error')
                        ->message("Customer Account not found.")->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('No POST data sent')->ok;
            }
        }
        elseif ($action == 'changepin')
        {
            is_set('CurrentPin:post', function ($post) use ($cid)
            {

                $data = AspNetUser::verify(['changepin', $post]);

                if ($data->isValid())
                {
                    $info = mw::customer()->info($cid);
                    $data = toArray($data);
                    unset($data['valid']);
                    $data['ID'] = $info
                        ->data->ID;

                    $http = new Http('esusu.changepin');
                    $http->post($data);
                    $http->send();

                    if (is_object($http->responseJson))
                    {
                        $msg = $http
                            ->responseJson->msg;
                        $status = 'Success';

                        if (preg_match('/(invalid)/i', $msg))
                        {
                            $status = 'Error';
                        }

                        $this->status($status)->message($msg)->ok;
                    }
                    else
                    {
                        $this->status('Error')
                            ->message("Operation failed. Please try again")->ok;
                    }

                    $http = null;
                }
                else
                {
                    $this->status('Error')
                        ->message('Invalid Post fields. Please see documentation.')->ok;
                }
            });

            if (!is_set('CurrentPin:post'))
            {
                $this->status('Error')
                    ->message('Invalid POST fields')->ok;
            }
        }
        elseif ($action == 'changepassword')
        {
            is_set('CurrentPassword:post&NewPassword:post&ConfirmPassword:post', function ($post)
            {
                $http = new Http('esusu.changepassword');
                $http->post($post)->send();

                if (is_object($http->responseJson))
                {
                    $msg = $http
                        ->responseJson->msg;

                    $status = false;

                    if (preg_match('/(Changed Successfully)/i', $msg))
                    {
                        $status = true;
                    }

                    if ($status)
                    {
                        $this->status('Success')
                            ->message($msg)->ok;
                    }
                    else
                    {
                        $this->status('Error')
                            ->message($msg)->ok;
                    }
                }
                else
                {
                    $this->status('Error')
                        ->message("Operation failed. Please try again")->ok;
                }

            });

            if (!is_set('CurrentPassword:post&NewPassword:post&ConfirmPassword:post'))
            {
                $this->status('Error')
                    ->message('An error occured. POST Data sent, please try again')->ok;
            }
        }
        elseif ($action == 'profile')
        {
            if (isset($_FILES['profile']))
            {
                $name = strip_tags($_FILES['profile']['name']);
                $tmp = $_FILES['profile']['tmp_name'];

                // move uploaded
                $dest = PATH_TO_IMAGE . $name;

                if (move_uploaded_file($tmp, $dest))
                {
                    $info = mw::customer()->info($cid);

                    $table = ORM::table('aspnetusers');

                    $user = $table->update(['profile' => $name], 'UserName = :un or UserName = :un2', $info
                        ->data->EmailAddress, $info
                        ->data
                        ->PhoneNumber);

                    $this->status('Success')
                        ->message('Upload successfull')->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message('Upload failed. Please try again later')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('Upload failed. Please try again later')->ok;
            }
        }
        else
        {
            $this->status('Error')
                ->message('Invalid Api request ' . $action)->ok;
        }

    }

    //@quickdoc{method: put, action:customer(), info:put data into customers table, param:$action{request-type} $cid(int){CustomerID} optional}
    public function putCustomer($action, $cid = null)
    {
        if ($action == 'add')
        {
            $role = Middleware::customer()->role($cid);
            $data = AspNetUser::filter_post();

            if ($role->Name != 'Customer')
            {
                if (count($data) > 0)
                {
                    $insert = DB::Customers(insert($data));

                    if ($insert->ok)
                    {
                        $this->status('Success')
                            ->message('Customer information added successfully')->ok;
                    }
                    else
                    {
                        $this->status('Error')
                            ->message('Failed! Could\'t submit POST Data.')
                            ->error(DB::$handler->error())->ok;
                    }
                }
                else
                {
                    $this->status('Error')
                        ->message('No POST Data sent. Please try again')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('You do not have such permission.')->ok;
            }
        }
        elseif ($action == 'bank')
        {
            if (is_set('AccountHolderName:post'))
            {
                is_set('AccountHolderName', function ($data) use ($cid)
                {

                    if (is_set('AccountNumber') && is_set('BankID'))
                    {
                        // check bank
                        $banks = DB::Banks(get()->where('ID = ?')
                            ->bind($data->BankID));

                        if ($banks->rows > 0)
                        {
                            $info = mw::customer()->info($cid);

                            // check if bank account exists
                            $check = DB::CustomerBankAccounts(get()->where('CustomerID = ?')
                                ->bind($info
                                ->data
                                ->ID));

                            if ($check->rows > 0)
                            {
                                $this->status('error')
                                    ->message('You have already added a bank account')->ok;
                            }
                            else
                            {
                                $data->CustomerID = $info
                                    ->data->ID;
                                $data->Status = 1;

                                // insert
                                $add = DB::CustomerBankAccounts(insert($data));

                                if ($add !== false)
                                {
                                    $this->status('success')
                                        ->message('Bank Account was added successfully')->ok;
                                }
                                else
                                {
                                    $this->status('error')
                                        ->message('Failed. Please try again')->ok;
                                }
                            }
                        }
                        else
                        {
                            $this->status('error')
                                ->message('Invalid Bank ID')->ok;
                        }
                    }
                    else
                    {
                        $this->status('error')
                            ->message('Invalid POST fields. See documentation')->ok;
                    }
                });
            }
            else
            {
                $this->status('error')
                    ->message('Invalid POST fields. See documentation')->ok;
            }
        }
        else
        {
            $this->status('Error')
                ->message('Invalid Api request ' . $action)->ok;
        }
    }

    public function getBank($cid = null)
    {
        $info = mw::customer()->info($cid);

        if ($info->ok)
        {
            $cid = $info
                ->data->ID;
            $bank = DB::CustomerBankAccounts(get()->where('CustomerID = ? and Status = ?')
                ->bind($cid, 1));

            if ($bank->rows > 0)
            {
                $bank = $bank->object;
                // get bank
                $getBank = DB::Banks(get()->where('ID = ? and Status = ?')
                    ->bind($bank->BankID, 1));

                if ($getBank->rows > 0)
                {
                    $bankName = $getBank->BankName;

                    $data = ['AccountHolderName' => $bank->AccountHolderName, 'AccountNumber' => $bank->AccountNumber, 'bank' => $bankName];

                    $this->status('Success')
                        ->data($data)->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message('Bank doesn\'t exist.')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('No bank account added.')->ok;
            }
        }
    }

    //@quickdoc{method:get action:banks, info:get all avaliable banks}
    public function getBanks($bankid = null)
    {
        if (!is_null($bankid))
        {
            $banks = DB::Banks(get()->where('ID = ? and Status = ?')
                ->bind($bankid, 1));
        }
        else
        {
            $banks = DB::Banks(get()->where('Status = ?')
                ->bind(1));
        }

        if ($banks->row > 0)
        {
            if ($banks->row == 1)
            {
                $this->status('success')
                    ->bankName($banks->BankName)
                    ->code($banks->Code)->ok;
            }
            else
            {
                $bnk = [];

                while ($b = $banks->object())
                {
                    $bnk[] = $b;
                }

                $this->status('success')
                    ->data($bnk)->ok;
            }
        }
        else
        {
            $this->status('error')
                ->message('Failed! please try again')->ok;
        }
    }

    public function getVerify($type, $cid = null)
    {
        $info = mw::customer()->info($cid);

        if ($type == 'phone')
        {
            if ($info
                ->data->IsPhoneNumberConfirmed == 0)
            {
                $post = [];
                $post['id'] = $info
                    ->data->ID;
                $post['phone'] = $info
                    ->data->PhoneNumber;
                $post['Message'] = '3';

                $http = new Http('esusu.verify.phone');
                $http->post($post);
                $http->send();

                if (is_object($http->responseJson))
                {
                    $this->status('Success')
                        ->message($http->responseJson)->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message("Operation failed. Please try again")->ok;
                }
            }
        }
    }

    public function postVerify($type, $cid = null)
    {
        // get customer info
        $info = mw::customer()->info($cid);

        if ($type == 'kyc')
        {
            if ($info
                ->data->IsKYCComplete == 0)
            {

                if (isset($_FILES['Passport']) && isset($_FILES['UtilityBill']) && isset($_FILES['Identification']))
                {

                    // check KYCUploads
                    $uploads = DB::KYCUploads(get()->where('CustomerID = ?')
                        ->bind($info
                        ->data
                        ->ID));

                    $continue = true;

                    if ($uploads->rows > 0)
                    {
                        $continue = false;
                    }

                    if ($continue === true)
                    {
                        $data = [];
                        foreach ($_FILES as $key => $val)
                        {
                            $data[$key] = new CURLFile($val['tmp_name'], $val['type'], $val['name']);
                        }

                        $data['IsAcknowledged'] = "true";
                        $data['CustomerID'] = $info
                            ->data->ID;

                        $http = new Http('esusu.verify.kyc');
                        $http->upload($data);
                        $http->send();

                        if (is_object($http->responseJson))
                        {
                            $res = $http->responseJson;

                            $status = $res->success == false ? 'Error' : 'Success';

                            $this->status($status)->message($res->msg)->ok;
                        }
                        else
                        {
                            $this->status('Error')
                                ->message("Operation failed. Please try again")->ok;
                        }

                    }
                    else
                    {
                        $this->status('pending')
                            ->message('Your doucments are currently being reviewed.')->ok;
                    }
                }
                else
                {
                    $this->status('Error')
                        ->message('Invalid POST Fields. Please see documentation')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('Your KYC has already been Verified')->ok;
            }
        }
        elseif ($type == 'bank')
        {
            if ($info
                ->data->IsBankAccountConfirmed == 0)
            {
                is_set('BankID:post', function ($post) use ($info, $cid)
                {
                    if (isset($post->AccountHolderName) && isset($post->AccountNumber) && isset($post->PIN))
                    {
                        $post->CustomerID = $info
                            ->data->ID;

                        $post = toArray($post);

                        $http = new Http('esusu.verify.bank');
                        $http->post($post);
                        $http->send();

                        if (is_object($http->responseJson))
                        {
                            $msg = $http
                                ->responseJson->msg;

                            if ($msg == 'true')
                            {
                                $this->status('Success')
                                    ->message('Bank Verified successfully')->ok;
                            }
                            else
                            {
                                $this->status('Error')
                                    ->message($msg)->ok;
                            }
                        }
                        else
                        {
                            $this->status('Error')
                                ->message("Operation failed. Please try again")->ok;
                        }

                    }
                    else
                    {
                        $this->status('Error')
                            ->message('Invalid POST Fields')->ok;
                    }
                });
            }
            else
            {
                $this->status('Error')
                    ->message('Bank Account Verified')->ok;
            }
        }
        elseif ($type == 'phone')
        {
            if ($info
                ->data->IsPhoneNumberConfirmed == 0)
            {

                is_set('EnteredToken:post', function ($data) use ($info)
                {

                    $post = [];
                    $post['id'] = $info
                        ->data->ID;
                    $post['phone'] = $info
                        ->data->PhoneNumber;
                    $post['EnteredToken'] = $data->EnteredToken;
                    $post['GeneratedToken'] = $data->GeneratedToken;
                    $post['Message'] = '2';

                    $http = new Http('esusu.verify.phone');
                    $http->post($post);
                    $http->send();

                    if (is_object($http->responseJson))
                    {
                        $msg = $http
                            ->responseJson->msg;
                        $status = 'Success';

                        if (preg_match('/(invalid)/i', $msg))
                        {
                            $status = 'Error';
                        }

                        $this->status($status)->message($msg)->ok;
                    }
                    else
                    {
                        $this->status('Error')
                            ->message("Operation failed. Please try again")->ok;
                    }
                });

            }
            else
            {
                $this->status('Error')
                    ->message('Your phone number has been verified.')->ok;
            }
        }
        else
        {
            $this->status('Error')
                ->message('Invalid Api request ' . $type)->ok;
        }
    }

    public function getSaving($action, $cid = null, $method = null)
    {
        $info = mw::customer()->info($cid);

        if ($action == 'plans')
        {
            if ($info
                ->data
                ->ID)
            {
                // Get plans
                $plans = DB::Products(get()->where('Status = ?')
                    ->bind(1));

                if ($plans->rows > 0)
                {
                    $data = [];

                    while ($d = $plans->object())
                    {
                        $interest = DB::Interests(get()->where('ID = ?')
                            ->bind($d->InterestID));

                        $d->Interest = $interest->Rate;
                        $d->MinimumBalance = $this->convertToNaria($d->MinimumBalance);
                        $d->MaximumBalance = $this->convertToNaria($d->MaximumBalance);
                        //$d->Balance = $this->convertToNaria($d->Balance);
                        $d->SavingsFrequency = $d->SavingsFrequency == 1 ? 'Daily' : 'Monthly';
                        $data[] = $d;
                    }

                    $this->status('Success')
                        ->plans($data)->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message('No Savings Plan Avaliable please try again later.')->ok;
                }
            }
        }
        elseif ($action == 'report')
        {
            $planCode = $cid;

            $limit = 20;
            if (isset($_GET['limit']))
            {
                $lm = filter_var($_GET['limit'], FILTER_VALIDATE_INT);
                if (is_integer($lm))
                {
                    $limit = $lm;
                }
            }

            $record = [];

            if ($method === null)
            {

                $wallet = DB::sql("SELECT * FROM RealTimePostings WHERE AccountNumber = ? ORDER BY ID DESC OFFSET 0 ROWS FETCH NEXT $limit ROWS ONLY")->bind($planCode)->run();

                if ($wallet->rows > 0 && $wallet->rows !== true)
                {
                    while ($re = $wallet->object())
                    {
                        $re->Amount = $this->convertToNaria($re->Amount);
                        $rec = $re->RecordType;
                        $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                        $re->BalanceAfterTrx = $this->convertToNaria($re->BalanceAfterTrx);

                        /*
                        switch ($re->PostingType) {
                        case 1:
                        $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                        break;
                        
                        case 2:
                        if (preg_match("/^(Credit Interest)/i", $re->Narration))
                        {
                        $re->RecordType = 'CR';
                        }
                        else
                        {
                        $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                        }
                        break;
                        
                        case 3:
                        if (preg_match("/^(Withdraw)/i", $re->Narration))
                        {
                        $re->RecordType = 'DR';
                        }
                        else
                        {
                        $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                        }
                        break;
                        case 4:
                        $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                        break;
                        case 5:
                        $re->RecordType = $rec == 2 ? 'DR' : 'CR';
                        break;
                        case 6:
                        
                        if (preg_match("/(funding)/i", $re->Narration) || preg_match("/^(Standing order for)/i", $re->Narration))
                        {
                        $re->RecordType = 'CR';
                        }
                        else
                        {
                        
                        if ($rec == 2)
                        {
                        $re->RecordType = 'CR';
                        }
                        else
                        {
                        $re->RecordType = 'DR';
                        }
                        }
                        break;
                        }
                        */

                        $re->PostingDate = date('Y-m-d g:i', strtotime($re
                            ->PostingDate
                            ->date));
                        $record[] = $re;
                    }
                }
            }
            else
            {
                $ID = $info
                    ->data->ID;

                $check = DB::CustomerProducts(get()->where('CustomerID = ?')
                    ->bind($ID));

                if ($check->row > 0)
                {
                    while ($c = $check->object())
                    {
                        $planCode = $c->Code;

                        $product = DB::Products(get()->where('ID = ?')
                            ->bind($c->ProductID));

                        $wallet = DB::sql("SELECT * FROM RealTimePostings WHERE AccountNumber = ? ORDER BY ID DESC OFFSET 0 ROWS FETCH NEXT $limit ROWS ONLY")->bind($planCode)->run();

                        if ($wallet->rows > 0 && $wallet->rows !== true)
                        {
                            while ($re = $wallet->object())
                            {
                                $re->Amount = $this->convertToNaria($re->Amount);
                                $rec = $re->RecordType;
                                $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                                $re->BalanceAfterTrx = $this->convertToNaria($re->BalanceAfterTrx);

                                /*
                                switch ($re->PostingType) {
                                case 1:
                                $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                                break;
                                
                                case 2:
                                if (preg_match("/^(Credit Interest)/i", $re->Narration))
                                {
                                $re->RecordType = 'CR';
                                }
                                else
                                {
                                $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                                }
                                break;
                                
                                case 3:
                                if (preg_match("/^(Withdraw)/i", $re->Narration))
                                {
                                $re->RecordType = 'DR';
                                }
                                else
                                {
                                $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                                }
                                break;
                                case 4:
                                $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                                break;
                                case 5:
                                $re->RecordType = $rec == 2 ? 'DR' : 'CR';
                                break;
                                case 6:
                                
                                if (preg_match("/(funding)/i", $re->Narration) || preg_match("/^(Standing order for)/i", $re->Narration))
                                {
                                $re->RecordType = 'CR';
                                }
                                else
                                {
                                
                                if ($rec == 2)
                                {
                                $re->RecordType = 'CR';
                                }
                                else
                                {
                                $re->RecordType = 'DR';
                                }
                                }
                                break;
                                }
                                */

                                $re->PostingDate = date('Y-m-d g:i', strtotime($re
                                    ->PostingDate
                                    ->date));
                                $re->plan = $product->Name;
                                $record[] = $re;
                            }
                        }
                    }
                }
            }

            $this->status('Success')
                ->report($record)->ok;
        }
        elseif ($action == 'interest')
        {
            $ID = $info
                ->data->ID;

            $check = DB::sql('SELECT * FROM CustomerProducts WHERE CustomerID = ?')->bind($ID)->run();

            if ($check->row > 0)
            {
                // get Products
                $data = [];

                while ($c = $check->object())
                {
                    if (!isset($data[$c->Code]))
                    {
                        $data[$c->Code] = 0.00;
                    }

                    // get interest
                    $interest = DB::sql("SELECT RealTimePostings.AccountNumber, RealTimePostings.Amount, RealTimePostings.Narration FROM RealTimePostings where Narration LIKE '%Credit Interest%' AND AccountNumber = ?")->bind($c->Code)
                        ->run();

                    if ($interest->row > 0)
                    {
                        while ($i = $interest->object())
                        {
                            $data[$c->Code] += floatval($i->Amount);
                        }
                    }

                    // add total interest for the current month
                    $data[$c
                        ->Code] += $this->getAccuredInterest($c->ID);

                }

                if (count($data) > 0)
                {
                    foreach ($data as $code => $amount)
                    {
                        $data[$code] = $this->convertToNaria($amount);
                    }
                }

                $this->status('Success')
                    ->interest($data)->ok;
            }
            else
            {
                $this->status('Error')
                    ->interest([])->ok;
            }

        }
        else
        {
            $this->status('Error')
                ->message('Invalid Api request ' . $action)->ok;
        }

    }

    public function getAccuredInterest($ProductID, $hide = true)
    {
        // get accured interest
        $http = new Http('esusu.savings.interest');
        $http->post(['CustProdID' => $ProductID])->send();

        $data = floatval($http->responseText);
        // print out
        if (!$hide)
        {
            $this->status('Success')
                ->interest(round(($data / 100) , 2))->ok;
        }
        // return data
        return round(($data / 100) , 2);
    }

    public function putSaving($action, $cid = null)
    {
        $info = mw::customer()->info($cid);

        if ($action == 'add')
        {
            is_set('ProductID:post&Amount:post', function ($post) use ($info)
            {

                $post->CustomerID = $info
                    ->data->ID;

                if (isset($post->TransferToWalletOnMaturity))
                {
                    $post->TransferToWalletOnMaturity = $post->TransferToWalletOnMaturity;
                }

                if (is_numeric($post->ProductID))
                {
                    $plan = DB::Products(get()->where('ID = ?')
                        ->bind($post->ProductID));

                    if ($plan->rows > 0)
                    {
                        $http = new Http('esusu.savings.add', $post->ProductID);
                        $http->post($post)->send();

                        if (is_object($http->responseJson))
                        {
                            $status = $http
                                ->responseJson->status === true ? 'Success' : 'Error';

                            $this->status($status)->message($http
                                ->responseJson
                                ->msg)->ok;
                        }
                        else
                        {
                            $this->status('Error')
                                ->message("Operation failed. Please try again")->ok;
                        }

                    }
                    else
                    {
                        $this->status('Error')
                            ->message("Invalid product Code.")->ok;
                    }
                }
                else
                {
                    $this->status('Error')
                        ->message('Invalid ProductID')->ok;
                }

            });

            if (!is_set('ProductID:post&Amount:post'))
            {
                $this->status('Error')
                    ->message('Invalid POST Fields.')->ok;
            }
        }
        elseif ($action == 'fund')
        {
            is_set('savingsPlanID:post&Amount:post&Description:post', function ($data) use ($info)
            {

                $http = new Http('esusu.savings.fund');
                $http->post($data)->send();

                if (is_object($http->responseJson))
                {
                    $msg = $http
                        ->responseJson->msg;

                    $status = 'Error';

                    if (stripos($msg, 'successfully') !== false)
                    {
                        $status = 'Success';
                    }

                    $this->status($status)->message($http
                        ->responseJson
                        ->msg)->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message("Operation failed. Please try again")->ok;
                }
            });

            if (!is_set('savingsPlanID:post&Amount:post&Description:post'))
            {
                $this->status('Error')
                    ->message('Invalid POST Fields')->ok;
            }
        }
        elseif ($action == 'close')
        {
            is_set('CustomerID:post&CustProdID:post', function ($data)
            {

                $http = new Http('esusu.savings.close');
                $http->post($data)->send();

                if (is_object($http->responseJson))
                {
                    $msg = $http
                        ->responseJson->msg;

                    $status = 'Error';

                    if (stripos($msg, 'successfully') !== false)
                    {
                        $status = 'Success';
                    }

                    $this->status($status)->message($http
                        ->responseJson
                        ->msg)->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message("Operation failed. Please try again")->ok;
                }
            });
        }
        else
        {
            $this->status('Error')
                ->message('Invalid Api request ' . $action)->ok;
        }
    }

    public function postWithdraw($type, $cid = null)
    {
        $info = mw::customer()->info($cid);

        if ($type == 'voucher')
        {
            if (isset($info
                ->data
                ->ID))
            {
                is_set('Amount:post&PIN:post', function ($data) use ($info)
                {

                    $isAgent = false;

                    if (isset($data->NumberOfVouchers))
                    {
                        $http = new Http('esusu.agent.withdraw');
                        $isAgent = true;
                    }
                    else
                    {
                        $http = new Http('esusu.withdraw.voucher');
                    }

                    $http->post($data)->send();

                    if ($isAgent === false)
                    {
                        if (is_object($http->responseJson))
                        {

                            $msg = $http
                                ->responseJson->msg??null;
                            $status = $http
                                ->responseJson->status??null;
                            $voucher = $http
                                ->responseJson->voucher??null;

                            if ($msg !== null && $voucher == null)
                            {
                                $this->status('Error')
                                    ->message($msg)->ok;
                            }
                            elseif ($voucher !== null)
                            {
                                $this->status('Success')
                                    ->message('Voucher generated.')
                                    ->voucher($voucher)->ok;
                            }
                            else
                            {
                                $this->status('Error')
                                    ->message("an error occured. please try again")->ok;
                            }
                        }
                        else
                        {
                            $this->status('Error')
                                ->message("Operation failed. Please try again")->ok;
                        }
                    }
                    else
                    {
                        if (isset($http
                            ->responseJson
                            ->status))
                        {
                            if ($http
                                ->responseJson
                                ->status)
                            {
                                $this->status('Success')
                                    ->voucher($http
                                    ->responseJson
                                    ->vouchers)->ok;
                            }
                            else
                            {
                                $this->status('Error')
                                    ->message($http
                                    ->responseJson
                                    ->msg)->ok;
                            }
                        }
                        else
                        {
                            $this->status('Error')
                                ->message("Sorry. Please try again.")->ok;
                        }
                    }

                });

                if (!is_set('Amount:post&PIN:post'))
                {
                    $this->status('Error')
                        ->message("Invalid POST fields")->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('Invalid CustomerID')->ok;
            }
        }
        elseif ($type == 'bank')
        {

            is_set('DepositAmount:post&PIN:post', function ($post) use ($info)
            {

                if (isset($info
                    ->data
                    ->ID))
                {
                    // add customer id
                    $post->CustomerID = $info
                        ->data->ID;
                    // PUSH DATA
                    $http = new Http('esusu.withdraw.bank');
                    $http->post($post)->send();

                    if (is_object($http->responseJson))
                    {
                        $msg = $http
                            ->responseJson->msg;

                        $status = 'Error';

                        if (preg_match('/(success)/i', $msg) == true)
                        {
                            $status = 'Success';
                        }

                        $this->status($status)->message($msg)->ok;

                    }
                    else
                    {
                        $this->status('Error')
                            ->message("Operation failed. Please try again")->ok;
                    }
                }
                else
                {
                    $this->status('Error')
                        ->message('Invalid CustomerID')->ok;
                }
            });

            if (!is_set('DepositAmount:post&PIN:post'))
            {
                $this->status('Error')
                    ->message('Invalid POST Field.')->ok;
            }

        }
        else
        {
            $this->status('Error')
                ->message('Invalid Api request ' . $type)->ok;
        }
    }

    public function postDeposit($type, $cid = null)
    {
        $info = mw::customer()->info($cid);

        if ($type == 'liquidate')
        {
            is_set('SerialNumber:post&VoucherCode:post&PIN:post', function ($post) use ($info)
            {

                if (isset($info
                    ->data
                    ->ID))
                {
                    $http = new Http('esusu.deposit.liquidate');
                    $http->post($post)->send();

                    if (is_object($http->responseJson))
                    {
                        $message = $http
                            ->responseJson->msg;
                        $status = 'Error';

                        if (preg_match("/(credited)/i", $message))
                        {
                            $status = 'Success';
                        }

                        $this->status($status)->message($message)->ok;
                    }
                    else
                    {
                        $this->status('Error')
                            ->message("Operation failed. Please try again")->ok;
                    }
                }
                else
                {
                    $this->status('Error')
                        ->message('Invalid CustomerID')->ok;
                }
            });

            if (!is_set('SerialNumber:post&VoucherCode:post&PIN:post'))
            {
                $this->status('Error')
                    ->message('Invalid POST Data.')->ok;
            }
        }
        elseif ($type == 'verify')
        {
            if (isset($info
                ->data
                ->ID))
            {
                is_set('reference:post&amount:post', function ($data)
                {

                    $http = new Http('esusu.verifytrx');
                    $http->post($data)->send();

                    ob_start();
                    var_dump($http);
                    $content = ob_get_contents();
                    ob_clean();

                    //file_put_contents('log.txt', $content);
                    

                    if (is_object($http->responseJson))
                    {
                        $msg = $http
                            ->responseJson->msg;
                        $status = 'Success';

                        if (preg_match("/(not)/i", $msg))
                        {
                            $status = 'Error';
                        }

                        $this->status($status)->message($msg)->ok;
                    }
                    else
                    {
                        $this->status('Error')
                            ->message('Transaction Failed. Please try again later.')->ok;
                    }
                });

                if (!is_set('reference:post&amount:post&__RequestVerificationToken:post'))
                {
                    $this->status('Error')
                        ->message('Invalid POST Fields')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('Invalid Customer Account')->ok;
            }
        }
        elseif ($type == 'verifyMonthly')
        {
            if (isset($info
                ->data
                ->ID))
            {
                is_set('reference:post&amount:post', function ($data)
                {

                    $http = new Http('esusu.verifytrx.monthly');
                    $http->post($data)->send();

                    if (is_object($http->responseJson))
                    {
                        $msg = $http
                            ->responseJson->message;
                        $status = 'Success';

                        if (preg_match("/(not)/i", $msg))
                        {
                            $status = 'Error';
                        }

                        $this->status($status)->message($msg)->ok;
                    }
                    else
                    {
                        $this->status('Error')
                            ->message('Transaction Failed. Please try again later.')->ok;
                    }

                });

                if (!is_set('reference:post&amount:post'))
                {
                    $this->status('Error')
                        ->message('Invalid POST Fields')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('Invalid Customer Account')->ok;
            }
        }
        else
        {
            $this->status('Error')
                ->request($type . ' is invalid!')->ok;
        }

    }

    public function getDeposit($type, $cid = null)
    {
        $info = mw::customer()->info($cid);

        if ($type == 'recurrent')
        {
            if (isset($info
                ->data
                ->ID))
            {
                $all = DB::RecurrentDeposits(get()->where('CustomerID = ?')
                    ->bind($info
                    ->data
                    ->ID));

                if ($all->rows > 0)
                {
                    $savings = [];

                    while ($a = $all->object())
                    {
                        $savings[] = $a;
                    }

                    $this->status('Success')
                        ->plans($savings)->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message('You have no active recurrent deposit savings.')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('Invalid CustomerID')->ok;
            }
        }
        elseif ($type == 'card')
        {
            if (isset($info
                ->data
                ->ID))
            {
                if ($info
                    ->data->IsBankAccountConfirmed === 1)
                {
                    $http = new Http('esusu.deposit.card');
                    $http->get()
                        ->send();

                    $this->status('Success')
                        ->trxRef($http->responseJson)->ok;
                }
                else
                {
                    $this->status('Error')
                        ->message('You have not added a bank account. Cannot proceed with request.')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('Invalid Customer Account')->ok;
            }
        }
        elseif ($type == 'cardMonthly')
        {
            if (isset($info
                ->data
                ->ID))
            {
                if ($info
                    ->data->IsBankAccountConfirmed === 1)
                {
                    $http = new Http('esusu.deposit.card.monthly');
                    $http->get()
                        ->send();

                    if (is_object($http->responseJson))
                    {
                        $this->status('Success')
                            ->trxRef($http
                            ->responseJson
                            ->trxRef)->ok;
                    }
                    else
                    {
                        $this->status('Error')
                            ->message('Authentication failed.')->ok;
                    }
                }
                else
                {
                    $this->status('Error')
                        ->message('You have not added a bank account. Cannot proceed with request.')->ok;
                }
            }
            else
            {
                $this->status('Error')
                    ->message('Invalid Customer Account')->ok;
            }
        }
        else
        {
            $this->status('Error')
                ->request($type . ' is invalid!')->ok;
        }
    }

    public function getReport($type, $cid = null)
    {
        $limit = 20;
        if (isset($_GET['limit']))
        {
            $lm = filter_var($_GET['limit'], FILTER_VALIDATE_INT);
            if (is_integer($lm))
            {
                $limit = $lm;
            }
        }

        if ($cid !== null)
        {

            $info = mw::customer()->info($cid);

            if ($type == 'wallet')
            {
                if ($info
                    ->data->IsBankAccountConfirmed === 1)
                {
                    if (isset($info
                        ->data
                        ->ID))
                    {
                        $account = DB::CustomerBankAccounts(get()->where('CustomerID = ?')
                            ->bind($info
                            ->data
                            ->ID));

                        $mobileAccount = DB::CustomerMobileAccounts(get()->where('CustomerID = ?')
                            ->bind($info
                            ->data
                            ->ID));

                        if ($account->rows > 0)
                        {
                            $AccountNumber = $account->AccountNumber;

                            if ($mobileAccount->rows > 0)
                            {
                                $wallet = DB::sql("SELECT * FROM RealTimePostings WHERE AccountNumber = ? OR AccountNumber = ? ORDER BY ID DESC OFFSET 0 ROWS FETCH NEXT $limit ROWS ONLY")->bind($AccountNumber, $mobileAccount->AccountNumber)
                                    ->run();
                            }
                            else
                            {
                                $wallet = DB::sql("SELECT * FROM RealTimePostings WHERE AccountNumber = ? ORDER BY ID DESC OFFSET 0 ROWS FETCH NEXT $limit ROWS ONLY")->bind($AccountNumber)->run();
                            }

                            if ($wallet->rows > 0)
                            {
                                $record = [];

                                if ($wallet->rows > 0 && $wallet->rows !== true)
                                {
                                    while ($re = $wallet->object())
                                    {
                                        // get account name
                                        $re->AccountHolderName = $account->AccountHolderName;
                                        $re->Amount = $this->convertToNaria($re->Amount);
                                        $rec = $re->RecordType;
                                        $re->BalanceAfterTrx = $this->convertToNaria($re->BalanceAfterTrx);

                                        /*
                                        switch ($re->PostingType) {
                                        case 1:
                                        $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                                        break;
                                        
                                        case 2:
                                        if (preg_match("/^(Credit Interest)/i", $re->Narration))
                                        {
                                        $re->RecordType = 'CR';
                                        }
                                        else
                                        {
                                        $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                                        }
                                        break;
                                        
                                        case 3:
                                        if (preg_match("/^(Withdraw)/i", $re->Narration))
                                        {
                                        $re->RecordType = 'DR';
                                        }
                                        else
                                        {
                                        $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                                        }
                                        break;
                                        case 4:
                                        case 5:
                                        $re->RecordType = $rec == 2 ? 'DR' : 'CR';
                                        break;
                                        case 6:
                                        
                                        if ($re->Narration == 'Savings Plan Funding' || preg_match("/^(Standing order for)/i", $re->Narration))
                                        {
                                        $re->RecordType = 'CR';
                                        }
                                        else
                                        {
                                        
                                        if ($rec == 2)
                                        {
                                        $re->RecordType = 'CR';
                                        }
                                        else
                                        {
                                        $re->RecordType = 'DR';
                                        }
                                        }
                                        break;
                                        }
                                        */
                                        $re->RecordType = $rec == 1 ? 'DR' : 'CR';

                                        $re->PostingDate = date('Y-m-d g:i', strtotime($re
                                            ->PostingDate
                                            ->date));
                                        $record[] = $re;
                                    }
                                }

                                $this->status('Success')
                                    ->report($record)->ok;
                            }
                            else
                            {
                                $this->status('Error')
                                    ->message('No Bank Transcation Report at this time')->ok;
                            }

                        }
                        else
                        {
                            $this->status('Error')
                                ->message('Invalid Customer Account')->ok;
                        }
                    }
                    else
                    {
                        $this->status('Error')
                            ->message('Invalid CustomerID')->ok;
                    }
                }
                else
                {
                    $this->status('Error')
                        ->message('Bank Account not confirmed and cannot proceed.')->ok;
                }
            }
            elseif ($type == 'voucher')
            {
                if (isset($info
                    ->data
                    ->ID))
                {

                    $batches = DB::sql("SELECT * FROM VoucherBatches WHERE CustomerID = ? ORDER BY ID DESC OFFSET 0 ROWS FETCH NEXT $limit ROWS ONLY")->bind($info
                        ->data
                        ->ID)
                        ->run();

                    if ($batches->rows > 0)
                    {
                        $data = [];

                        if ($batches->rows > 1)
                        {
                            $index = 0;

                            while ($b = $batches->object())
                            {
                                $voucher = DB::sql("SELECT * FROM Vouchers WHERE BatchID = ?")->bind($b->ID)
                                    ->run();

                                if ($voucher->rows > 0)
                                {
                                    $created = is_array($voucher->DateCreated) ? date('Y-m-d g:i:s a', strtotime($voucher->DateCreated['date'])) : null;
                                    $used = is_array($voucher->DateUsed) ? date('Y-m-d g:i:s a', strtotime($voucher->DateUsed['date'])) : null;

                                    $ch = curl_init('https://esusuonline.com/ewalletAdmin/decryptvoucher/decrypt?decryptme=' . $voucher->VoucherCode);
                                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                                    $code = curl_exec($ch);
                                    curl_close($ch);

                                    $code = strlen($code) > 15 ? null : $code;

                                    $data[$index]['BatchNumber'] = $b->BatchNumber;
                                    $data[$index]['SerialNumber'] = $voucher->SerialNumber;
                                    $data[$index]['VoucherCode'] = $code;
                                    $data[$index]['Amount'] = $voucher->Value;
                                    $data[$index]['DateCreated'] = $created;
                                    $data[$index]['DateUsed'] = $used;
                                    $data[$index]['Initiator'] = $info
                                        ->data->Lastname . ' ' . $info
                                        ->data->Othernames;
                                    $data[$index]['Status'] = $voucher->Status == 2 ? 'Used' : 'Avaliable';
                                    $data[$index]['Beneficiary'] = $voucher->LiquidaterName;
                                    $data[$index]['BeneficiaryAccount'] = $voucher->LiquidaterAccount;
                                }

                                $index++;
                            }
                        }
                        else
                        {
                            $voucher = DB::sql("SELECT * FROM Vouchers WHERE BatchID = ?")->bind($batches->ID)
                                ->run();

                            if ($voucher->rows > 0)
                            {
                                $created = is_array($voucher->DateCreated) ? date('Y-m-d g:i:s a', strtotime($voucher->DateCreated['date'])) : null;
                                $used = is_array($voucher->DateUsed) ? date('Y-m-d g:i:s a', strtotime($voucher->DateUsed['date'])) : null;

                                $ch = curl_init('http://esusuonline.com/ewalletAdmin/decryptvoucher/decrypt?decryptme=' . $voucher->VoucherCode);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                                $code = curl_exec($ch);
                                curl_close($ch);

                                $code = strlen($code) > 15 ? null : $code;

                                $data[0]['BatchNumber'] = $batches->BatchNumber;
                                $data[0]['SerialNumber'] = $voucher->SerialNumber;
                                $data[0]['VoucherCode'] = $code;
                                $data[0]['Amount'] = $voucher->Value;
                                $data[0]['DateCreated'] = $created;
                                $data[0]['DateUsed'] = $used;
                                $data[0]['Initiator'] = $info
                                    ->data->Lastname . ' ' . $info
                                    ->data->Othernames;
                                $data[0]['Status'] = $voucher->Status == 2 ? 'Used' : 'Avaliable';
                                $data[0]['Beneficiary'] = $voucher->LiquidaterName;
                                $data[0]['BeneficiaryAccount'] = $voucher->LiquidaterAccount;
                            }
                        }

                        $this->status('Success')
                            ->report($data)->ok;
                    }
                    else
                    {
                        $this->status('Error')
                            ->message('No Voucher Transcation Report at this time')->ok;
                    }

                }
                else
                {
                    $this->status('Error')
                        ->message('Invalid CustomerID')->ok;
                }
            }
            elseif ($type == 'all')
            {

                $account = DB::CustomerBankAccounts(get()->where('CustomerID = ?')
                    ->bind($info
                    ->data
                    ->ID));
                $mobileAccount = DB::CustomerMobileAccounts(get()->where('CustomerID = ?')
                    ->bind($info
                    ->data
                    ->ID));

                $record = [];
                $data = [];

                if ($account->rows > 0)
                {
                    $AccountNumber = $account->AccountNumber;
                    $limit = 1000;

                    if ($mobileAccount->rows > 0)
                    {
                        $wallet = DB::sql("SELECT * FROM RealTimePostings WHERE AccountNumber = ? OR AccountNumber = ? ORDER BY ID DESC OFFSET 0 ROWS FETCH NEXT $limit ROWS ONLY")->bind($AccountNumber, $mobileAccount->AccountNumber)
                            ->run();
                    }
                    else
                    {
                        $wallet = DB::sql("SELECT * FROM RealTimePostings WHERE AccountNumber = ? ORDER BY ID DESC OFFSET 0 ROWS FETCH NEXT $limit ROWS ONLY")->bind($AccountNumber)->run();
                    }

                    if ($wallet->rows > 0 && $wallet->rows !== true)
                    {
                        while ($re = $wallet->object())
                        {
                            // get account name
                            $re->AccountHolderName = $account->AccountHolderName;
                            $re->Amount = $this->convertToNaria($re->Amount);
                            $rec = $re->RecordType;

                            $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                            $re->BalanceAfterTrx = $this->convertToNaria($re->BalanceAfterTrx);

                            /*
                            switch ($re->PostingType) {
                            case 1:
                            $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                            break;
                            
                            case 2:
                            if (preg_match("/^(Credit Interest)/i", $re->Narration))
                            {
                            $re->RecordType = 'CR';
                            }
                            else
                            {
                            $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                            }
                            break;
                            
                            case 3:
                            if (preg_match("/^(Withdraw)/i", $re->Narration))
                            {
                            $re->RecordType = 'DR';
                            }
                            else
                            {
                            $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                            }
                            break;
                            case 4:
                            case 5:
                            $re->RecordType = $rec == 2 ? 'DR' : 'CR';
                            break;
                            case 6:
                            
                            if ($re->Narration == 'Savings Plan Funding' || preg_match("/^(Standing order for)/i", $re->Narration))
                            {
                            $re->RecordType = 'CR';
                            }
                            else
                            {
                            
                            if ($rec == 2)
                            {
                            $re->RecordType = 'CR';
                            }
                            else
                            {
                            $re->RecordType = 'DR';
                            }
                            }
                            break;
                            }
                            */

                            $re->PostingDate = date('Y-m-d g:i', strtotime($re
                                ->PostingDate
                                ->date));
                            $record[] = $re;
                        }
                    }
                }

                /*
                $ID = $info->data->ID;
                
                $check = DB::CustomerProducts(get()->where('CustomerID = ?')->bind($ID));
                
                if ($check->row > 0)
                {
                while($c = $check->object())
                {
                $planCode = $c->Code;
                
                $wallet = DB::sql("SELECT * FROM RealTimePostings WHERE AccountNumber = ? ORDER BY ID DESC OFFSET 0 ROWS FETCH NEXT $limit ROWS ONLY")->bind($planCode)->run();
                
                if ($wallet->rows > 0 && $wallet->rows !== true)
                {
                while($re = $wallet->object())
                {
                // get account name
                $re->AccountHolderName = $account->AccountHolderName;
                $re->Amount = $this->convertToNaria($re->Amount);
                $rec = $re->RecordType;
                $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                
                /*
                switch ($re->PostingType) {
                case 1:
                $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                break;
                
                case 2:
                if (preg_match("/^(Credit Interest)/i", $re->Narration))
                {
                $re->RecordType = 'CR';
                }
                else
                {
                $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                }
                break;
                
                case 3:
                if (preg_match("/^(Withdraw)/i", $re->Narration))
                {
                $re->RecordType = 'DR';
                }
                else
                {
                $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                }
                break;
                case 4:
                $re->RecordType = $rec == 1 ? 'DR' : 'CR';
                break;
                case 5:
                $re->RecordType = $rec == 2 ? 'DR' : 'CR';
                break;
                case 6:
                
                if (preg_match("/(funding)/i", $re->Narration) || preg_match("/^(Standing order for)/i", $re->Narration))
                {
                $re->RecordType = 'CR';
                }
                else
                {
                
                if ($rec == 2)
                {
                $re->RecordType = 'CR';
                }
                else
                {
                $re->RecordType = 'DR';
                }
                }
                break;
                }
                
                
                $re->PostingDate = date('Y-m-d g:i', strtotime($re->PostingDate->date));
                $record[] = $re;
                }
                }
                }
                }
                */

                $this->status('Success')
                    ->report($record)->ok;
            }
            elseif ($type == 'deposits')
            {
                $account = DB::CustomerBankAccounts(get()->where('CustomerID = ?')
                    ->bind($info
                    ->data
                    ->ID));
                $mobileAccount = DB::CustomerMobileAccounts(get()->where('CustomerID = ?')
                    ->bind($info
                    ->data
                    ->ID));

                $record = [];
                $data = [];

                if ($account->rows > 0)
                {
                    $AccountNumber = $account->AccountNumber;

                    if ($mobileAccount->rows > 0)
                    {
                        $wallet = DB::sql("SELECT * FROM RealTimePostings WHERE AccountNumber = ? OR AccountNumber = ? ORDER BY ID DESC OFFSET 0 ROWS FETCH NEXT $limit ROWS ONLY")->bind($AccountNumber, $mobileAccount->AccountNumber)
                            ->run();
                    }
                    else
                    {
                        $wallet = DB::sql("SELECT * FROM RealTimePostings WHERE AccountNumber = ? ORDER BY ID DESC OFFSET 0 ROWS FETCH NEXT $limit ROWS ONLY")->bind($AccountNumber)->run();
                    }

                    if ($wallet->rows > 0 && $wallet->rows !== true)
                    {
                        while ($re = $wallet->object())
                        {
                            $naration = $re->Narration;

                            if (preg_match('/(credit|deposit|Wallet Credit)/i', $naration))
                            {
                                // get account name
                                $re->AccountHolderName = $account->AccountHolderName;
                                $re->Amount = $this->convertToNaria($re->Amount);
                                $re->RecordType = 'CR';
                                $re->BalanceAfterTrx = $this->convertToNaria($re->BalanceAfterTrx);

                                $re->PostingDate = date('Y-m-d g:i', strtotime($re
                                    ->PostingDate
                                    ->date));
                                $record[] = $re;
                            }
                        }
                    }
                }

                $this->status('Success')
                    ->report($record)->ok;
            }
            else
            {
                $this->status('Error')
                    ->message('Invalid Api request ' . $type)->ok;
            }
        }
        else
        {
            $this->status('Error')
                ->message('Invalid Customer Account')->ok;
        }
    }

    private function convertToNaria($val)
    {
        $val = floatval($val) / 100;
        $num = number_format($val, 2, '.', ',');
        return $num;
    }

    // send vouchers to agent
    public function postAgentVouchers($id)
    {
        $info = mw::customer()->info($id);

        if ($info->ok)
        {
            $vouchers = [];

            if (isset($_POST['vouchers']))
            {
                foreach ($_POST['vouchers'] as $key => $voucher)
                {
                    // get index
                    preg_match('/^[\d]{1,}/', $key, $index);

                    if (isset($index[0]))
                    {
                        // remove from key
                        $key = preg_replace('/[\d]{1,}/', '', $key);
                        $vouchers[$index[0]][$key] = $voucher;
                    }
                }
            }

            if (count($vouchers) > 0)
            {
                $id = 1;
                $Vouchers = [];
                $Vouchers[] = ['SN', 'Serial Number', 'Voucher Code'];
                foreach ($vouchers as $voucher)
                {
                    $Vouchers[] = [$id, $voucher['SerialNumber'], $voucher['VoucherCode']];
                    $id++;
                }

                // create csv document
                $path = md5('voucher_' . time() . '_' . $id) . '.csv';

                // open
                $fh = fopen(PATH_TO_PUBLIC . 'tmp/' . $path, 'w+');

                // push
                foreach ($Vouchers as $line)
                {
                    fputcsv($fh, $line, ',');
                }

                // close handle
                fclose($fh);

                // link
                $link = 'http://esusuonline.com.ng:90/voucher/download?name=' . $path;

                // get email
                $email = $info
                    ->data->EmailAddress;

                // send email out.
                Plugins::mail()
                    ->send($email, 'Vouchers ready for download', 'Good day! Please click on this link to download your vouchers. ' . $link);

                // send output
                $this->status('Success')
                    ->message('Request processed and vouchers has been sent to ' . $email . ' in an excel format. Please check your mailbox to download.')->ok;

            }

            $this->status('Error')
                ->message('Could not send voucher to client.')->ok;
        }
        else
        {
            $this->status('Error')
                ->message('Authentication Required')->ok;
        }
    }

    // get
    public function getVoucher($method)
    {
        if ($method == 'download')
        {
            is_set('name:get', function ($get)
            {
                $path = PATH_TO_PUBLIC . 'tmp/' . $get->name;
                if (file_exists($path))
                {
                    // download
                    $mime = mime_content_type($path);

                    header('Content-Type: ' . $mime);
                    header('Content-Disposition: attachment; filename=' . basename($path));
                    header('Content-Length: ' . filesize($path));
                    header('Pragma: public');
                    header('Cache-Control: must-revalidate');
                    header('Expires: 0');
                    flush();
                    readfile($path);

                }
                else
                {
                    $this->status('Error')
                        ->message('Voucher Requested doesn\'t exists anymore.')->ok;
                }
            });
        }
        else
        {
            $this->status('Error')
                ->message('Invalid Request.')->ok;
        }
    }

    // send support ticket
    public function postSendticket($id)
    {
        $info = mw::customer()->info($id);

        if ($info->ok)
        {
            is_set('type:post&title:post&message:post', function ($post) use (&$info, $id)
            {
                $table = '<br><br><table style="border: 1px solid #eee; box-shadow: 0px 0px 10px rgba(0,0,0,0.2);
	padding: 10px; display: table; width: 100%;">';

                $rows = [];
                $data = $info->data;

                // add rows
                $rows[] = ['Fullname', $data->Lastname . ' ' . $data->Othernames];
                $rows[] = ['Country', 'Nigeria'];
                $rows[] = ['Telephone', $data->PhoneNumber];
                $rows[] = ['Email Address', '<a href="mailto:' . $data->EmailAddress . '">' . $data->EmailAddress . '</a>'];
                $rows[] = ['Ticket Type', $post->type];
                $rows[] = ['Ticket Title', $post->title];
                $rows[] = ['Message', $post->message];

                foreach ($rows as $i => $row)
                {
                    $get_row = '';
                    array_walk($row, function ($text, $index) use (&$get_row)
                    {
                        $extra = '';
                        if ($index == 0)
                        {
                            $extra = 'border-left: 1px solid #eee;';
                        }
                        $get_row .= '<td style="border-top: 1px solid #eee;
	border-right: 1px solid #eee; padding: 10px; ' . $extra . '">' . $text . '</td>' . "\n";
                    });
                    $table .= '<tr>' . $get_row . '</tr>';
                }

                $table .= '</table>';

                // send to support
                Plugins::mail()->send('esusuonline@consumermfb.com.ng', ucfirst($post->type) . ' Support Ticket', 'You have a new support ticket. See details below.' . $table);

                // send to customer
                Plugins::mail()->send($data->EmailAddress, ucfirst($post->type) . ' Support Ticket', 'Your Ticket has been raised. You will get a feedback from us as soon as possible. <br> You can find in summary a brief detail of ticket raised by you below. ' . $table);

                $this->status('Success')
                    ->message('Your ticket has been raised successfully. Please check you mail for a reply.')->ok;

            });
        }
    }

    // get users
    public function getUsers()
    {
        // return total users
        $topup = 100000;

        include_once 'lab/data/sqlsrv.php';

        $db = new Data\DB\Sqlsrv();
        $con = $db->connect();
        $db->instance = $con;
        $db->prepare('SELECT * FROM Customers');
        $db->execute();

        $rows = 0;

        while ($a = sqlsrv_fetch_array($db->lastQuery))
        {
            $rows++;
        }

        // sum
        $topup += $rows;
        // return
        $this->status('Success')
            ->users($topup)->ok;
    }
}


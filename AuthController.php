<?php

namespace App\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\Request;
use App\Models\HomeScreen;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
//use Illuminate\Support\Facades\RegisrationMail;
class AuthController extends BaseController
{
    /**
     * Register api
     *
     * @return \Illuminate\Http\Response
     */
    public function register(Request $request)
    {
        if (User::where('email', $request['email'])->exists()){
            return response()->json([
                'status' => false,
                'message' => 'Email is already exists.',
            ]);
        }
        $validator = Validator::make($request->all(), [
            'fname' => 'required',
            'lname' => 'required',
            'nickname' => 'required',
            'phone' => 'required',

            'email' => 'required|email',
            'password' => 'required',
            'c_password' => 'required|same:password',
        ]);

        if($validator->fails()){
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $input = $request->all();
        $input['uuid'] = Str::uuid();
        $input['status'] = 1;
        $input['email_otp'] = rand(100000,999999);
        $input['password'] = Hash::make($input['password']);
        $input['verify_token'] = bin2hex(random_bytes(50));
        $user = User::create($input);
        $success['token'] =  $user->createToken('MyApp')->plainTextToken;
        $success['name'] =  $user->name;
        Mail::to($user->email)->send(new RegisrationMail($user));
        return $this->sendResponse($success, 'User register successfully.');
    }


    /**
     * Login api
     *
     * @return \Illuminate\Http\Response
     */
    public function login(Request $request)
    {
        if(Auth::attempt(['email' => $request->email, 'password' => $request->password])){
            $user = Auth::user();
            $success['token'] =  $user->createToken('MyApp')->plainTextToken;
            $success['name'] =  $user->name;

            return $this->sendResponse($success, 'User login successfully.');
        }
        else{
            return $this->sendError('Unauthorised.', ['error'=>'Unauthorised']);
        }
    }

    public function emailVeify(Request $request){
        $status = $this->verifyUser($request);
        $userStatus = false;
        if ($status == null) {
            $message = 'Email does not exist.';
        } else if ($status == 'already_verified') {
            $message = 'Your email is already verified.';
        } else if ($status == 'token_expired') {
            $message = 'Email Verification link has been expired.';
        } else {
            $userStatus = true;
            $message = 'You have been verified. Now you can sign in into the system.';
        }
        return response()->json([
            'status' => $userStatus,
            'message' => $message,
        ]);
    }
    public function verifyUser($request)
    {
        $verify_token = $request['verify_token'];
        $user = User::where('uuid',$request['id'])->first();
        if (is_null($user)) {
            return null;
        } else if ($user->status == 1) {
            if ($user->verify_token == $verify_token) {
                $this->updateStatusOfUser($user);
                return 'verified';
            } else {
                return 'token_expired';
            }
        } else {
            return 'already_verified';
        }

    }

    protected function validateEmail(Request $request)
    {
        $request->validate(['email' => 'required|email'], [
            'email.required' => 'Please enter an email.'
        ]);
    }

    public function sendResetLinkEmail(Request $request)
    {
        $this->validateEmail($request);

        $user = User::where('email', $request['email'])->first();
        if ($user == null) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid Email Address.',
            ]);
        }
        $credentials = ['email' => $request['email']];
        $response = Password::sendResetLink($credentials, function (Message $message) {
            $message->subject($this->getEmailSubject());
        });
        dd($response);

        switch ($response) {
            case Password::RESET_LINK_SENT:
                return redirect()->back()->with('status', trans($response));
            case Password::INVALID_USER:
                return redirect()->back()->withErrors(['email' => trans($response)]);
        }
    }

    public function updateStatusOfUser($user)
    {
        $user->status = 2;
        $user->email_verified_at = now();
        $user->update();
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $products = User::all();
        return $this->sendResponse($products, 'Products retrieved successfully.');
    }
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function redirectToFB()
    {
        return Socialite::driver('facebook')->redirect();
    }

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function handleCallback()
    {
        try {

            $user = Socialite::driver('facebook')->user();

            $finduser = User::where('social_id', $user->id)->first();

            if($finduser){

                Auth::login($finduser);

                return redirect('/home');

            }else{
                $newUser = User::create([
                    'name' => $user->name,
                    'email' => $user->email,
                    'social_id'=> $user->id,
                    'social_type'=> 'facebook',
                    'password' => encrypt('my-facebook')
                ]);

                Auth::login($newUser);

                return redirect('/home');
            }

        } catch (\Exception $e) {
            dd($e->getMessage());
        }
    }
    public function homescreen(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            array(

                //'images' => 'nullable|image|mimes:jpeg,png,jpg,gif',
                'user_id'=>'required',
                'time'=>'required',
                'distance'=>'required',
                'food_name'=>'required',
                'payment_mood'=>'required',
                'rating'=>'required',
                'food_picture'=>'required|image|mimes:jpeg,png,jpg,gif',
                'food_logo'=>'required|image|mimes:jpeg,png,jpg,gif',

            ));
        if ($validator->fails()) {
            return response()->json(['errors'=>$validator->errors()]);
        }


        if(User::where('id', $request->user_id)->exists()){
            $img = HomeScreen::create($request->all());
            $food_picture = $request->food_picture;
            $destination = 'public/images/foodPicture';
            if ($request->hasFile('food_picture')) {
                $filename = strtolower(
                    pathinfo($food_picture->getClientOriginalName(), PATHINFO_FILENAME)
                    . '-'
                    . uniqid()
                    . '.'
                    . $food_picture->getClientOriginalExtension()
                );
                str_replace(" ", "-", $filename);
                $food_picture->move($destination, $filename);
                $img->food_picture = $filename;
                $img->save();
            }
        $food_logo = $request->food_logo;
        $destination = 'public/images/foodPicture';
        if ($request->hasFile('food_logo')) {
            $filename = strtolower(
                pathinfo($food_logo->getClientOriginalName(), PATHINFO_FILENAME)
                . '-'
                . uniqid()
                . '.'
                . $food_logo->getClientOriginalExtension()
            );
            str_replace(" ", "-", $filename);
            $food_logo->move($destination, $filename);
            $img->food_logo = $filename;
            $img->save();
        }


            //$data = 'Bearer' . ' ' . $user->createToken('MyApp')->accessToken;
            $response_array = array('data' =>$img ,'message' =>'Successfully Add Menu!!' ,'status_code' => 200);
            $response = response()->json($response_array, 200);
            return $response;
    } else {
return response()->json([
'status' => false,
'message' => 'User Id not found'
]);
}}
}

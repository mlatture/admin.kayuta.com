<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
class AdminController extends Controller
{
    public function index()
    {
        $admins = Admin::with('role')
            ->whereKeyNot(auth()->id()) 
            ->get();

        foreach ($admins as $admin) {
            if (!$admin->relationLoaded('role')) {
                \Illuminate\Support\Facades\Log::warning('Role not loaded for admin', ['admin_id' => $admin->id]);
            }
        }

        return view('admin.index', compact('admins'));
    }
    
    
    
  
    public function create()
    {
        $data['organizations'] = Organization::get();
        $data['adminRoles'] = AdminRole::where('id','!=','1')->where('is_pos',true)->get();
        return view('admin.create',$data);
    }

    public function store(Request $request){
        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:admins,email',
            'password' => 'required',
            'admin_role_id' => 'required|exists:admin_roles,id',
            'image' => 'nullable|file',
            'status' => 'required|boolean'
        ]);
        $adminData = $request->except(['_token','image']);
        $adminData['password'] = Hash::make($adminData['password']);
        if($request->image and $request->hasFile('image')){
            $fileName = time().'.'.$request->file('image')->getClientOriginalExtension();
            $request->file('image')->move(public_path('uploaded_data'),$fileName);
            $adminData['image'] = $fileName;
        }
        Admin::create($adminData);
        return redirect()->route('admins.index')->with('success','Admin created successfully');
    }

    public function edit($id)
    {
        $data['organizations'] = Organization::get();
        $data['adminRoles'] = AdminRole::where('id','!=','1')->where('is_pos',true)->get();
        $data['admin'] = Admin::findOrfail($id);
        return view('admin.edit',$data);
    }

    public function update(Request $request, $id) {
        $request->validate([
            'name' => 'required',
            'email' => 'required|email',
            'password' => 'nullable',
          
            'admin_role_id' => 'required|exists:admin_roles,id',
            'image' => 'nullable|file',
            'status' => 'required|boolean'
        ]);
        $adminData = $request->except(['_token','image','password']);
        if($request->password and $request->has('password')){
            $adminData['password'] = Hash::make($request->password);
        }
        if($request->image and $request->hasFile('image')){
            $fileName = time().'.'.$request->file('image')->getClientOriginalExtension();
            $request->file('image')->move(public_path('uploaded_data'),$fileName);
            $adminData['image'] = $fileName;
        }
        Admin::findOrFail($id)->update($adminData);
        return redirect()->route('admins.index')->with('success','Admin updated successfully');
    }

    public function destroy($id) {
        Admin::findOrFail($id)->delete();
        return redirect()->route('admins.index')->with('success','Admin deleted successfully');
    }

    public function profile(){
        $users = auth()->user();

        return view('layouts.partials.navbar', compact('users'));
    }
}

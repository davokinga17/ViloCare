@extends('layouts.app')

@section('title', 'My Profile')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">My Staff Profile</h1>
        <p class="page-subtitle">Manage your personal clinic information and security credentials.</p>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px;">
    <!-- Profile Info Card -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Personal Information</h2>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 20px;">
                    <div id="profile-avatar-display" class="user-avatar" style="width: 56px; height: 56px; font-size: 1.25rem; overflow: hidden; border-radius: 50%; flex-shrink: 0;">
                        @if($user->photo_url)
                            <img src="{{ $user->photo_url }}" alt="{{ $user->name }}" class="user-avatar-img" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block;">
                        @else
                            {{ strtoupper(substr($user->name, 0, 2)) }}
                        @endif
                    </div>
                    <div>
                        <div style="font-weight: 700; font-size: 1.05rem; color: #0f172a;">{{ $user->name }}</div>
                        <div style="color: #0d9488; font-size: 0.85rem; font-weight: 600;">{{ $user->role->label() }}</div>
                        <div style="color: #64748b; font-size: 0.8rem;">{{ $user->email }}</div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="name" class="form-label">Full Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required class="form-control">
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Contact Phone</label>
                    <input type="tel" inputmode="tel" id="phone" name="phone" value="{{ old('phone', $user->phone) }}" class="form-control" placeholder="+211910000000">
                </div>

                <div class="form-group">
                    <label for="photo" class="form-label">Profile Photo (Optional)</label>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <input type="file" id="photo" name="photo" class="form-control" accept="image/*" style="flex: 1; min-width: 220px;">
                        <button type="button" class="btn btn-secondary" id="btn-take-photo" data-camera-capture data-target="#photo" data-title="Take profile photo" style="display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                            Take Photo
                        </button>
                    </div>

                    <!-- Photo Preview Box when a new photo is chosen/captured -->
                    <div id="photo-preview-box" style="display: none; margin-top: 12px; padding: 10px 14px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-md); align-items: center; gap: 12px;">
                        <img id="photo-preview-img" src="" alt="Photo preview" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid #0d9488;">
                        <div style="flex: 1; min-width: 0;">
                            <div id="photo-preview-name" style="font-size: 0.85rem; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">photo.jpg</div>
                            <div style="font-size: 0.75rem; color: #0d9488; font-weight: 500;">✓ Ready to save. Click "Update Profile Details" below to apply.</div>
                        </div>
                        <button type="button" id="btn-remove-selected-photo" class="btn btn-sm btn-secondary" style="color: #dc2626; border-color: #fecaca;" title="Remove selected photo">
                            ✕ Clear
                        </button>
                    </div>

                    <div class="form-help">Upload an image (JPG, PNG, WebP up to 2MB) or capture one directly with your camera.</div>
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top: 8px;">
                    Update Profile Details
                </button>
            </form>
        </div>
    </div>

    <!-- Change Password Card -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Update Password</h2>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('profile.password') }}">
                @csrf
                @method('PUT')

                <div class="form-group">
                    <label for="current_password" class="form-label">Current Password <span class="required">*</span></label>
                    <input type="password" id="current_password" name="current_password" required class="form-control">
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">New Password <span class="required">*</span></label>
                    <input type="password" id="password" name="password" required class="form-control">
                    <div class="form-help">Minimum 8 characters with letters and numbers.</div>
                </div>

                <div class="form-group">
                    <label for="password_confirmation" class="form-label">Confirm New Password <span class="required">*</span></label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required class="form-control">
                </div>

                <button type="submit" class="btn btn-secondary" style="margin-top: 8px;">
                    Change Password
                </button>
            </form>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script src="{{ \App\Support\AssetVersion::url('js/camera-capture.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const photoInput = document.getElementById('photo');
    const previewBox = document.getElementById('photo-preview-box');
    const previewImg = document.getElementById('photo-preview-img');
    const previewName = document.getElementById('photo-preview-name');
    const btnClearPhoto = document.getElementById('btn-remove-selected-photo');
    const avatarDisplay = document.getElementById('profile-avatar-display');
    const defaultAvatarHtml = avatarDisplay ? avatarDisplay.innerHTML : '';

    function showPhotoPreview(url, name) {
        if (previewImg) previewImg.src = url;
        if (previewName) previewName.textContent = name;
        if (previewBox) previewBox.style.display = 'flex';
        if (avatarDisplay) {
            avatarDisplay.innerHTML = `<img src="${url}" alt="Profile Preview" class="user-avatar-img" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block;">`;
        }
    }

    // Fires for a chosen file and for a photo taken with the camera button (js/camera-capture.js sets the
    // same #photo field and fires "change"), so the form upload below is the same either way.
    if (photoInput) {
        photoInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const file = this.files[0];
                showPhotoPreview(URL.createObjectURL(file), file.name);
            }
        });
    }

    if (btnClearPhoto) {
        btnClearPhoto.addEventListener('click', function() {
            if (photoInput) photoInput.value = '';
            if (previewBox) previewBox.style.display = 'none';
            if (avatarDisplay) avatarDisplay.innerHTML = defaultAvatarHtml;
        });
    }
});
</script>
@endpush

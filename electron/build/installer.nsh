; Custom NSIS steps for the ViLoCare installer (per-machine).
; 1. Install the Visual C++ 2015-2022 x64 runtime if missing (php.exe needs it).
; 2. Create %PROGRAMDATA%\ViLoCare and let every local user modify it.
; Uninstall never touches %PROGRAMDATA%\ViLoCare, so patient data survives uninstall/update.

; 0. Before anything is replaced (also during silent auto-updates): stop any php.exe that runs from the installed
;    app. The app stops its own PHP server and scheduler before it starts the installer, but a leftover one (crash,
;    force-closed app, old version) would keep resources\php\php.exe locked and make the file replacement fail.
;    Only php.exe files inside THIS install folder are touched. The data folder in ProgramData is never touched.
!macro customInit
  SetRegView 64
  ReadRegStr $R0 HKLM "${INSTALL_REGISTRY_KEY}" InstallLocation
  ${If} $R0 != ""
    nsExec::Exec "powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command $\"Get-Process php -ErrorAction SilentlyContinue | Where-Object { $$_.Path -like '$R0\resources\php\*' } | Stop-Process -Force$\""
    Pop $R1
  ${EndIf}
!macroend

!macro customInstall
  SetRegView 64
  ReadRegDWORD $0 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Installed"
  ${If} $0 != 1
    File "/oname=$PLUGINSDIR\vc_redist.x64.exe" "${BUILD_RESOURCES_DIR}\vc_redist.x64.exe"
    ExecWait '"$PLUGINSDIR\vc_redist.x64.exe" /install /quiet /norestart' $1
    ; 0 = ok, 3010 = ok (reboot suggested), 1638 = newer version already present
    ${If} $1 != 0
    ${AndIf} $1 != 3010
    ${AndIf} $1 != 1638
      MessageBox MB_OK|MB_ICONEXCLAMATION "The Microsoft Visual C++ runtime could not be installed (code $1). ViLoCare may not start until it is installed from https://aka.ms/vs/17/release/vc_redist.x64.exe"
    ${EndIf}
  ${EndIf}

  CreateDirectory "$%PROGRAMDATA%\ViLoCare"
  ; *S-1-5-32-545 = BUILTIN\Users, (OI)(CI)M = modify, inherited by files and subfolders.
  ; /T also applies it to files that already exist (database.sqlite and its -wal/-shm files, sessions, logs), so
  ; every Windows account can write them; files created later inherit it from the folder.
  nsExec::Exec 'icacls "$%PROGRAMDATA%\ViLoCare" /grant *S-1-5-32-545:(OI)(CI)M /T /C /Q'
  Pop $2
!macroend

!macro customUnInstall
  ; Intentionally empty: data folder is kept.
!macroend

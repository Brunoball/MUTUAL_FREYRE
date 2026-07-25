import React from "react";
import { Navigate, Route, Routes } from "react-router-dom";
import ProtectedRoute from "./components/Login/rutas/ProtectedRoute";
import GuestRoute from "./components/Login/rutas/GuestRoute";
import PermissionGate from "./components/Login/rutas/PermissionGate";
import AppLayout from "./components/Principal/Principal";
import InicioSesion from "./components/Login/InicioSesion";
import DashboardPage from "./components/Dashboard/DashboardPage";
import PersonasPage from "./components/Personas/PersonasPage";
import AyudasPage from "./components/Ayudas/AyudasPage";
import ConfiguracionPage from "./components/Configuracion/ConfiguracionPage";
import UsuariosSistemaPage from "./components/Configuracion/secciones/UsuariosSistemaPage";
import AuditoriaPage from "./components/Auditoria/AuditoriaPage";

export default function AppRouter() {
  return (
    <Routes>
      <Route element={<GuestRoute />}><Route path="/" element={<InicioSesion />} /></Route>
      <Route element={<ProtectedRoute />}>
        <Route element={<AppLayout />}>
          <Route path="/panel" element={<PermissionGate permission="dashboard.view"><DashboardPage /></PermissionGate>} />
          <Route path="/personas" element={<PermissionGate permission="personas.view"><PersonasPage /></PermissionGate>} />
          <Route path="/ayudas" element={<PermissionGate permission="ayudas.view"><AyudasPage /></PermissionGate>} />
          <Route path="/configuracion" element={<PermissionGate permission="configuracion.view"><ConfiguracionPage /></PermissionGate>} />
          <Route path="/configuracion/usuarios" element={<PermissionGate permission="configuracion.view"><UsuariosSistemaPage /></PermissionGate>} />
          <Route path="/auditoria" element={<PermissionGate permission="auditoria.view"><AuditoriaPage /></PermissionGate>} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/panel" replace />} />
    </Routes>
  );
}

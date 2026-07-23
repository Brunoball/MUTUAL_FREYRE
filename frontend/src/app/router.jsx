import React from "react";
import { Navigate, Route, Routes } from "react-router-dom";
import ProtectedRoute from "./ProtectedRoute";
import GuestRoute from "./GuestRoute";
import PermissionGate from "./PermissionGate";
import AppLayout from "../shared/AppLayout";
import InicioSesion from "../modules/login/InicioSesion";
import DashboardPage from "../modules/dashboard/DashboardPage";
import PersonasPage from "../modules/personas/PersonasPage";
import AyudasPage from "../modules/ayudas/AyudasPage";
import ConfiguracionPage from "../modules/configuracion/ConfiguracionPage";
import UsuariosSistemaPage from "../modules/configuracion/UsuariosSistemaPage";

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
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/panel" replace />} />
    </Routes>
  );
}

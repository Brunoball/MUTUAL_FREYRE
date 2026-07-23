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
import CobranzasPage from "../modules/cobranzas/CobranzasPage";
import CuentasSociosPage from "../modules/cuentas/CuentasSociosPage";
import AhorrosTerminoPage from "../modules/ahorros/AhorrosTerminoPage";
import ValoresPage from "../modules/valores/ValoresPage";
import CajaPage from "../modules/caja/CajaPage";
import BancosPage from "../modules/bancos/BancosPage";
import ContabilidadPage from "../modules/contabilidad/ContabilidadPage";
import DocumentosPage from "../modules/documentos/DocumentosPage";
import ReportesPage from "../modules/reportes/ReportesPage";
import AuditoriaPage from "../modules/auditoria/AuditoriaPage";
import ConfiguracionPage from "../modules/configuracion/ConfiguracionPage";

export default function AppRouter() {
  return (
    <Routes>
      <Route element={<GuestRoute />}><Route path="/" element={<InicioSesion />} /></Route>
      <Route element={<ProtectedRoute />}>
        <Route element={<AppLayout />}>
          <Route path="/panel" element={<PermissionGate permission="dashboard.view"><DashboardPage /></PermissionGate>} />
          <Route path="/personas" element={<PermissionGate permission="personas.view"><PersonasPage /></PermissionGate>} />
          <Route path="/ayudas" element={<PermissionGate permission="ayudas.view"><AyudasPage /></PermissionGate>} />
          <Route path="/cobranzas" element={<PermissionGate permission="cobranzas.view"><CobranzasPage /></PermissionGate>} />
          <Route path="/cuentas" element={<PermissionGate permission="cuentas.view"><CuentasSociosPage /></PermissionGate>} />
          <Route path="/ahorros" element={<PermissionGate permission="ahorros.view"><AhorrosTerminoPage /></PermissionGate>} />
          <Route path="/valores" element={<PermissionGate permission="valores.view"><ValoresPage /></PermissionGate>} />
          <Route path="/caja" element={<PermissionGate permission="caja.view"><CajaPage /></PermissionGate>} />
          <Route path="/bancos" element={<PermissionGate permission="bancos.view"><BancosPage /></PermissionGate>} />
          <Route path="/contabilidad" element={<PermissionGate permission="contabilidad.view"><ContabilidadPage /></PermissionGate>} />
          <Route path="/documentos" element={<PermissionGate permission="documentos.view"><DocumentosPage /></PermissionGate>} />
          <Route path="/reportes" element={<PermissionGate permission="reportes.view"><ReportesPage /></PermissionGate>} />
          <Route path="/auditoria" element={<PermissionGate permission="auditoria.view"><AuditoriaPage /></PermissionGate>} />
          <Route path="/configuracion" element={<PermissionGate permission="configuracion.view"><ConfiguracionPage /></PermissionGate>} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/panel" replace />} />
    </Routes>
  );
}

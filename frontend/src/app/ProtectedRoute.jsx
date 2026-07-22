import React from "react";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "./AuthProvider";
import LoadingScreen from "../shared/LoadingScreen";

export default function ProtectedRoute() {
  const auth = useAuth();
  const location = useLocation();
  if (auth.status === "loading") return <LoadingScreen text="Verificando sesión..." />;
  if (!auth.isAuthenticated) return <Navigate to="/" replace state={{ from: location }} />;
  return <Outlet />;
}

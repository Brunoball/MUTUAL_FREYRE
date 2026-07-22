import React from "react";
import { useAuth } from "./AuthProvider";
import ForbiddenPage from "../shared/ForbiddenPage";

export default function PermissionGate({ permission, children }) {
  const { can } = useAuth();
  return can(permission) ? children : <ForbiddenPage />;
}
